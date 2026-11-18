<?php

namespace App\Actions\JobSearch;

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use App\Support\Concurrency\RetriedTransaction;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Hired to Cancelled: the one way out of Hired. One application, never in bulk and never inside a job order close.
 *
 * In one transaction, retried after a deadlock or a lock timeout (RetriedTransaction), the application becomes Cancelled and
 * its Job Search record goes from Complete back to In Progress, so the same engagement can continue with another job order.
 * Nothing else is written: no fee, payment, refund, remark or document, and the job order is not edited, reopened or even
 * written, so a Done or Cancelled order stays as it is. Job Order Money and Unassigned Kept Amount are worked out from these
 * states, so the old order loses the attribution and the money is Unassigned until a new application exists, with no
 * financial row created.
 *
 * Locks, in the agreed order: the job order (only to keep the order of locks, its status is not a gate), the person, all of
 * the person's Job Search records in ascending id, then the record's applications. Everything is read again once locked.
 *
 * Refused, with nothing changed:
 * - the application is no longer Hired, or its record is not Complete (that one is logged: normal workflows cannot make it);
 * - the person was deleted: their Hired application and its attribution stay exactly as they are;
 * - the person has another In Progress Job Search record: the person must not end up with two, and no other record is changed.
 *
 * The transaction closure runs up to three times, so it does no logging or other side effect.
 */
class CancelHiredApplication
{
    /**
     * @throws DomainException when a refusal above applies
     */
    public function handle(JobOrderWorker $application): JobOrderWorker
    {
        try {
            return RetriedTransaction::run(fn () => $this->cancel($application));
        } catch (InconsistentEngagement $exception) {
            Log::warning($exception->getMessage(), $exception->context);

            throw $exception;
        }
    }

    private function cancel(JobOrderWorker $application): JobOrderWorker
    {
        // The order is locked first only so every workflow takes its locks in the same direction. Its status does not matter.
        JobOrder::query()->lockForUpdate()->findOrFail($application->job_order_id);

        $client = Client::withTrashed()->lockForUpdate()->findOrFail($application->client_id);

        if ($client->trashed()) {
            throw new DomainException(__(':name was deleted, so the application cannot change.', ['name' => $client->name]));
        }

        $jobSearchId = Service::query()->where('key', Service::JOB_SEARCH_KEY)->value('id');

        $records = ClientService::query()
            ->where('client_id', $client->id)
            ->where('service_id', $jobSearchId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var ClientService|null $record */
        $record = $records->firstWhere('id', $application->client_service_id);

        // Locked after its record, in ascending id with the others, then read again: only this row is needed.
        $locked = JobOrderWorker::query()
            ->where('client_service_id', $application->client_service_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->firstWhere('id', $application->id);

        if ($locked === null || $locked->case_status !== WorkerCaseStatus::Hired) {
            throw new DomainException(__('The application of :name is no longer Hired, so it cannot be cancelled. Please review it and try again.', ['name' => $client->name]));
        }

        if ($record === null || $record->status !== ClientServiceStatus::Complete) {
            throw new InconsistentEngagement([
                'reason' => 'Hired application on a Job Search record that is not Complete',
                'client_id' => $client->id,
                'client_service_id' => $application->client_service_id,
                'job_order_worker_id' => $application->id,
            ]);
        }

        if ($records->contains(fn (ClientService $other) => $other->id !== $record->id && $other->status === ClientServiceStatus::InProgress)) {
            throw new DomainException(__(':name already has another Job Search service in progress. Resolve that one first (for example complete it, or cancel it from the client page), then cancel this Hired application.', ['name' => $client->name]));
        }

        $locked->update(['case_status' => WorkerCaseStatus::Cancelled]);
        $record->update(['status' => ClientServiceStatus::InProgress]);

        return $locked;
    }
}
