<?php

namespace App\Actions\JobSearch;

use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Support\Concurrency\RetriedTransaction;
use DomainException;
use InvalidArgumentException;

/**
 * Closes an open job order as Done or Cancelled, resolving its open applications first, in ONE transaction that is retried
 * after a deadlock or a lock timeout (RetriedTransaction). If any step fails, everything rolls back: no partial resolution
 * and no partial close.
 *
 * In this order, inside the one transaction:
 * 1. The Applied or Interview applications of people who were deleted are set to Cancelled. The admin does not resolve them.
 * 2. The admin's resolutions for everyone else are applied: Hired, Rejected or Cancelled for every open application, with the
 *    same checks and the same Hired consequences (the Job Search record becomes Complete) as a plain status update.
 * 3. The order is closed.
 *
 * Nothing else changes automatically: Hired, Rejected and Cancelled applications stay as they are, and nothing is auto-rejected.
 * Job Order Money is worked out from the statuses, so a cancelled application leaves the closed order's Money without any
 * payment, refund or other financial row, and the Job Search record's kept amount stays as it was.
 *
 * Locks, in ascending id inside each level: the job order, the people (deleted ones too), their Job Search records, then the
 * applications. Everything is read again once locked and compared with what the admin's screen showed; any difference aborts
 * the whole close (JobOrderChangedException).
 *
 * The transaction closure runs up to three times, so it does no logging or other side effect.
 */
class CloseJobOrder
{
    public function __construct(private UpdateApplicationStatuses $updateStatuses) {}

    /**
     * @param  array<int, WorkerCaseStatus>  $resolutions  application id => Hired, Rejected or Cancelled, one for every open application of a person who is not deleted
     * @param  array<int, WorkerCaseStatus>  $expectedOpen  application id => status of the open applications of people who are not deleted, as the admin's screen showed them
     * @param  array<int, int>  $expectedDeletedOpen  ids of the open applications of deleted people, as the admin's screen showed them
     *
     * @throws InvalidArgumentException when asked to close as open
     * @throws JobOrderChangedException when the order or its open applications are not what the screen showed
     * @throws DomainException when the order is already closed, or a resolution is missing or not allowed
     */
    public function handle(JobOrder $jobOrder, JobOrderStatus $status, array $resolutions, array $expectedOpen, array $expectedDeletedOpen): void
    {
        if ($status === JobOrderStatus::Open) {
            throw new InvalidArgumentException('An order is closed as done or cancelled, never as open.');
        }

        RetriedTransaction::run(function () use ($jobOrder, $status, $resolutions, $expectedOpen, $expectedDeletedOpen): void {
            $this->close($jobOrder, $status, $resolutions, $expectedOpen, $expectedDeletedOpen);
        });
    }

    /**
     * @param  array<int, WorkerCaseStatus>  $resolutions
     * @param  array<int, WorkerCaseStatus>  $expectedOpen
     * @param  array<int, int>  $expectedDeletedOpen
     */
    private function close(JobOrder $jobOrder, JobOrderStatus $status, array $resolutions, array $expectedOpen, array $expectedDeletedOpen): void
    {
        $order = JobOrder::query()->lockForUpdate()->findOrFail($jobOrder->id);

        if ($order->isClosed()) {
            throw new DomainException('This job order is already closed.');
        }

        // With the order locked no application can be added or change status: every workflow that does takes this lock first.
        $open = JobOrderWorker::query()->where('job_order_id', $order->id)->open()->orderBy('id')->get(['id', 'client_id', 'client_service_id']);

        $clients = Client::withTrashed()
            ->whereIn('id', $open->pluck('client_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        ClientService::query()
            ->whereIn('id', $open->pluck('client_service_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $rows = JobOrderWorker::query()->where('job_order_id', $order->id)->open()->orderBy('id')->lockForUpdate()->get();

        [$deleted, $live] = $rows->partition(fn (JobOrderWorker $row) => $clients[$row->client_id]->trashed());

        $actualOpen = $live->mapWithKeys(fn (JobOrderWorker $row) => [$row->id => $row->case_status])->all();

        // partition() keeps the original positions, so the ids are re-indexed before they are compared.
        $deletedIds = $deleted->pluck('id')->values()->all();

        if ($this->values($actualOpen) !== $this->values($expectedOpen) || $deletedIds !== $this->sorted($expectedDeletedOpen)) {
            throw new JobOrderChangedException;
        }

        if ($this->sorted(array_keys($resolutions)) !== $this->sorted(array_keys($actualOpen))) {
            throw new DomainException('Every open application must be resolved before the job order can close.');
        }

        foreach ($resolutions as $resolved) {
            if (! in_array($resolved, [WorkerCaseStatus::Hired, WorkerCaseStatus::Rejected, WorkerCaseStatus::Cancelled], true)) {
                throw new DomainException('An open application can only be resolved as Hired, Rejected or Cancelled.');
            }
        }

        foreach ($deleted as $row) {
            $row->update(['case_status' => WorkerCaseStatus::Cancelled]);
        }

        if ($resolutions !== []) {
            $this->updateStatuses->applyLocked($order, $resolutions);
        }

        $order->markClosed($status);
    }

    /**
     * Application ids with their status values, in ascending id, for an exact comparison.
     *
     * @param  array<int, WorkerCaseStatus>  $statuses
     * @return array<int, string>
     */
    private function values(array $statuses): array
    {
        ksort($statuses);

        return array_map(fn (WorkerCaseStatus $status) => $status->value, $statuses);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
