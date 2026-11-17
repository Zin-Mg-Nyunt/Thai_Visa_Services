<?php

namespace App\Actions\JobSearch;

use App\Enums\ClientServiceStatus;
use App\Enums\EngagementState;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrderWorker;
use App\Models\Service;

/**
 * Looks at a person's In Progress Job Search record (engagement) and decides what adding them to a job order may do. Every
 * application of the record is checked, never only the latest.
 *
 * - No In Progress record: None. Old Complete or Cancelled records are history and do not matter.
 * - Any application Applied or Interview: OpenApplication, a strict block.
 * - Every application Rejected or Cancelled: Reusable.
 * - Anything else is Inconsistent, because the normal workflows cannot produce it: more than one In Progress record, a record
 *   with no application at all, or a Hired application on a record that should be Complete.
 *
 * With $lock the rows are locked in the agreed order, the person's In Progress record first and then its applications in
 * ascending id, so the answer stays true until the transaction ends. The person must already be locked by the caller.
 */
class FindEngagement
{
    public function for(Client $client, Service $jobSearch, bool $lock = false): EngagementCheck
    {
        $records = $client->clientServices()
            ->where('service_id', $jobSearch->id)
            ->where('status', ClientServiceStatus::InProgress->value)
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        if ($records->isEmpty()) {
            return new EngagementCheck(EngagementState::None);
        }

        if ($records->count() > 1) {
            return new EngagementCheck(EngagementState::Inconsistent, null, [
                'reason' => 'more than one In Progress Job Search record',
                'client_id' => $client->id,
                'client_service_ids' => $records->modelKeys(),
            ]);
        }

        /** @var ClientService $record */
        $record = $records->first();

        $statuses = JobOrderWorker::query()
            ->where('client_service_id', $record->id)
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'case_status'])
            ->pluck('case_status');

        $context = ['client_id' => $client->id, 'client_service_id' => $record->id];

        if ($statuses->isEmpty()) {
            return new EngagementCheck(EngagementState::Inconsistent, null, $context + ['reason' => 'In Progress Job Search record with no application']);
        }

        if ($statuses->contains(fn (WorkerCaseStatus $status) => in_array($status, [WorkerCaseStatus::Applied, WorkerCaseStatus::Interview], true))) {
            return new EngagementCheck(EngagementState::OpenApplication, null, $context);
        }

        if ($statuses->contains(WorkerCaseStatus::Hired)) {
            return new EngagementCheck(EngagementState::Inconsistent, null, $context + ['reason' => 'Hired application on an In Progress Job Search record']);
        }

        return new EngagementCheck(EngagementState::Reusable, $record, $context);
    }
}
