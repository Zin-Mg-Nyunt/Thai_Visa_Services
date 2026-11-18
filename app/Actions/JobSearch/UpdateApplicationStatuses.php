<?php

namespace App\Actions\JobSearch;

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Support\Concurrency\RetriedTransaction;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Changes the status of one or several applications of an open job order, all or nothing. One application is a bulk update of
 * one. Each application may get its own new status, which is also what the job order close needs.
 *
 * One transaction, retried after a deadlock or a lock timeout (RetriedTransaction). Locks, always in ascending id inside each
 * level: the job order, the people, their Job Search records, then the applications. Everything is read again once locked and
 * only then checked, so two admins changing the same application cannot both succeed: the second sees the first one's result.
 *
 * - The order must still be open.
 * - The person must not be deleted, and the Job Search record must still be In Progress (a Complete or Cancelled record keeps
 *   its applications as they are).
 * - Each change must be an allowed transition (WorkerCaseStatus::allowedNext).
 * - Hired completes the Job Search record in the same transaction. Completing is final: nothing in the app reopens it.
 *
 * The caller has already asked the admin to confirm Hired. The transaction closure runs up to three times, so it has no side
 * effects. Messages are built here, but nothing is logged or flashed in it.
 */
class UpdateApplicationStatuses
{
    /**
     * @param  array<int, WorkerCaseStatus>  $changes  application (job_order_workers) id => its new status
     * @return Collection<int, JobOrderWorker> the changed applications
     *
     * @throws DomainException when the order is closed, an application is not on it, or a change is not allowed
     */
    public function handle(JobOrder $jobOrder, array $changes): Collection
    {
        return RetriedTransaction::run(fn () => $this->apply($jobOrder, $changes));
    }

    /**
     * @param  array<int, WorkerCaseStatus>  $changes
     * @return Collection<int, JobOrderWorker>
     */
    private function apply(JobOrder $jobOrder, array $changes): Collection
    {
        $order = JobOrder::query()->lockForUpdate()->findOrFail($jobOrder->id);

        if ($order->isClosed()) {
            throw new DomainException('This job order is already closed.');
        }

        return $this->applyLocked($order, $changes);
    }

    /**
     * Check and apply the changes. The caller is inside a transaction and already holds the job order's lock (the first level
     * of the lock order), and has checked that the order is open. This is also what the job order close uses for the admin's
     * resolutions, so a close and a plain status update follow exactly the same rules.
     *
     * @param  array<int, WorkerCaseStatus>  $changes
     * @return Collection<int, JobOrderWorker>
     *
     * @throws DomainException when an application is not on the order or a change is not allowed
     */
    public function applyLocked(JobOrder $order, array $changes): Collection
    {
        $ids = array_keys($changes);
        sort($ids);

        // Find out whose rows these are with a plain read, then lock level by level and read again.
        $found = JobOrderWorker::query()->where('job_order_id', $order->id)->whereIn('id', $ids)->get(['id', 'client_id', 'client_service_id']);

        if ($found->count() !== count($ids)) {
            throw new DomainException('One of these applications is not on this job order.');
        }

        $clients = Client::withTrashed()
            ->whereIn('id', $found->pluck('client_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $records = ClientService::query()
            ->whereIn('id', $found->pluck('client_service_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $applications = JobOrderWorker::query()
            ->where('job_order_id', $order->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($applications as $application) {
            $this->check($application, $changes[$application->id], $clients[$application->client_id], $records[$application->client_service_id]);
        }

        foreach ($applications as $application) {
            $next = $changes[$application->id];

            $application->update(['case_status' => $next]);

            if ($next === WorkerCaseStatus::Hired) {
                $records[$application->client_service_id]->update(['status' => ClientServiceStatus::Complete]);
            }
        }

        return $applications;
    }

    private function check(JobOrderWorker $application, WorkerCaseStatus $next, Client $client, ClientService $record): void
    {
        if ($client->trashed()) {
            throw new DomainException(__(':name was deleted, so the application cannot change.', ['name' => $client->name]));
        }

        if ($record->status !== ClientServiceStatus::InProgress) {
            throw new DomainException(__('The Job Search record of :name is no longer in progress, so the application cannot change.', ['name' => $client->name]));
        }

        if (! $application->case_status->canChangeTo($next)) {
            throw new DomainException(__('The application of :name cannot change from :from to :to.', [
                'name' => $client->name,
                'from' => __(ucfirst($application->case_status->value)),
                'to' => __(ucfirst($next->value)),
            ]));
        }
    }
}
