<?php

namespace App\Actions\JobSearch;

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Puts a person on a job order. In one transaction it creates the person when they are new (an existing person is
 * reused), the person's Job Search service record (fee, payments and documents live there) and the worker row that
 * links the two to the order.
 *
 * The order row is locked and checked again inside the transaction, so a worker cannot slip onto an order that was
 * closed a moment ago. The worker count is never limited: manpower is the company's request, not a limit.
 */
class AddWorker
{
    /**
     * @param  int|null  $clientId  An existing person, or null to create one from $person.
     * @param  array<string, mixed>  $person  name, phone, birthday, gender (and optional remarks) of a new person.
     * @param  numeric-string  $fee  The worker's fee, an exact decimal string.
     *
     * @throws DomainException when the order is closed, the person is already on it, or the Job Search service is missing
     */
    public function handle(JobOrder $jobOrder, ?int $clientId, array $person, string $fee): JobOrderWorker
    {
        return DB::transaction(function () use ($jobOrder, $clientId, $person, $fee): JobOrderWorker {
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

            if ($order->workers()->where('client_id', $client->id)->exists()) {
                throw new DomainException('This person is already on this job order.');
            }

            $record = $client->clientServices()->create([
                'service_id' => $jobSearch->id,
                'status' => ClientServiceStatus::InProgress,
                'fee' => $fee,
            ]);

            return $order->workers()->create([
                'client_id' => $client->id,
                'client_service_id' => $record->id,
                'case_status' => WorkerCaseStatus::Applied,
            ]);
        });
    }
}
