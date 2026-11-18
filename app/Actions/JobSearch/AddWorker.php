<?php

namespace App\Actions\JobSearch;

use App\Enums\ClientServiceStatus;
use App\Enums\EngagementState;
use App\Enums\RemarkKind;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use App\Support\Concurrency\RetriedTransaction;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Puts a person on a job order. One transaction, retried after a deadlock or a lock timeout (RetriedTransaction), does it all.
 *
 * - A new person is created; an existing person is reused.
 * - A person with no In Progress Job Search record gets a new one (the fee, payments and documents live there) and a first
 *   application on this order.
 * - A person whose In Progress record has only Rejected or Cancelled applications keeps that record: a new application row
 *   is added for this order and the old rows are never touched. The fee may be kept or changed, with the same refund rule as
 *   every fee change.
 * - A person with an application still Applied or Interview, or with a record that normal workflows cannot produce, is
 *   refused and nothing is changed. The impossible cases are logged.
 * - The same person may be added to the same order again when their earlier applications on it are all Rejected or Cancelled: a
 *   new row is created and the old rows stay as they are. An Applied, Interview or Hired application on this order refuses.
 *
 * Locks, in this order: the job order, the person, the person's In Progress record, then its applications. The order is
 * checked again once locked, so a worker cannot slip onto an order that was closed a moment ago. The worker count is never
 * limited: manpower is the company's request, not a limit.
 *
 * The transaction closure runs up to three times, so it does no logging or other side effect. That happens after it.
 */
class AddWorker
{
    public function __construct(private FindEngagement $findEngagement) {}

    /**
     * @param  int|null  $clientId  An existing person, or null to create one from $person.
     * @param  array<string, mixed>  $person  name, phone, birthday, gender (and optional remarks) of a new person.
     * @param  numeric-string  $fee  The worker's fee, an exact decimal string. For a reused record it is the fee after this step.
     * @param  int|null  $userId  The admin, written on a refund remark when the fee change leaves money to refund.
     *
     * @throws DomainException when the order is closed, the person is already on it, has an open application, or the record is
     *                         inconsistent, or the Job Search service is missing
     * @throws ValidationException when another admin used the same phone number a moment ago
     */
    public function handle(JobOrder $jobOrder, ?int $clientId, array $person, string $fee, ?int $userId = null): JobOrderWorker
    {
        try {
            return RetriedTransaction::run(fn () => $this->add($jobOrder, $clientId, $person, $fee, $userId));
        } catch (InconsistentEngagement $exception) {
            Log::warning($exception->getMessage(), $exception->context);

            throw $exception;
        } catch (UniqueConstraintViolationException) {
            // Two admins created the same new person at once: the database caught the second. It is not a retry case.
            throw ValidationException::withMessages([
                'phone' => trans('validation.unique', ['attribute' => trans('validation.attributes.phone')]),
            ]);
        }
    }

    /**
     * @param  numeric-string  $fee
     * @param  array<string, mixed>  $person
     */
    private function add(JobOrder $jobOrder, ?int $clientId, array $person, string $fee, ?int $userId): JobOrderWorker
    {
        $order = JobOrder::query()->lockForUpdate()->findOrFail($jobOrder->id);

        if ($order->isClosed()) {
            throw new DomainException('This job order is already closed.');
        }

        $jobSearch = Service::query()->where('key', Service::JOB_SEARCH_KEY)->first();

        if ($jobSearch === null) {
            throw new DomainException('The Job Search service is not set up.');
        }

        $client = $clientId !== null
            ? Client::query()->lockForUpdate()->findOrFail($clientId)
            : Client::create($person);

        // The same person may come back to this order after a Rejected or Cancelled application (a new row is added), but not
        // while an application of theirs here is Applied, Interview or Hired. The order and the person are locked, so another
        // request for the same person and order waits here and then sees this one's row.
        if ($order->workers()->where('client_id', $client->id)->whereIn('case_status', WorkerCaseStatus::activeValues())->lockForUpdate()->first(['id']) !== null) {
            throw new DomainException('This person is already on this job order.');
        }

        $engagement = $this->findEngagement->for($client, $jobSearch, lock: true);

        $record = match ($engagement->state) {
            EngagementState::None => $client->clientServices()->create([
                'service_id' => $jobSearch->id,
                'status' => ClientServiceStatus::InProgress,
                'fee' => $fee,
            ]),
            EngagementState::Reusable => $this->keepOrChangeFee($engagement->record, $fee, $userId),
            EngagementState::OpenApplication => throw new DomainException('This person already has an open application (Applied or Interview). Reject or cancel it before adding them to another job order.'),
            EngagementState::Inconsistent => throw new InconsistentEngagement($engagement->context),
        };

        return $order->workers()->create([
            'client_id' => $client->id,
            'client_service_id' => $record->id,
            'case_status' => WorkerCaseStatus::Applied,
        ]);
    }

    /**
     * Keep the fee as it is, or change it to the new one. A fee below the money already received leaves the difference as a
     * refund, and a refund remark records it, exactly as when the fee is changed on the client's page. The record is locked by
     * the caller.
     *
     * @param  numeric-string  $fee
     */
    private function keepOrChangeFee(ClientService $record, string $fee, ?int $userId): ClientService
    {
        if (bccomp($record->fee, $fee, 2) === 0) {
            return $record;
        }

        $details = ['fee' => $fee];
        $refund = $record->refundForFee($fee);

        if (bccomp($refund, $record->refund_amount, 2) !== 0) {
            $details['refund_amount'] = $refund;

            $record->remarks()->create([
                'kind' => RemarkKind::Refund,
                'details' => ['from_fee' => $record->fee, 'to_fee' => $fee, 'received' => $record->received(), 'refund' => $refund],
                'created_by' => $userId,
            ]);
        }

        $record->update($details);

        return $record;
    }
}
