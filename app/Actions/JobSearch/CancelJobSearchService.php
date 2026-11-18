<?php

namespace App\Actions\JobSearch;

use App\Enums\ClientServiceStatus;
use App\Enums\RemarkKind;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrderWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whole-service cancellation of a Job Search service (business rules, Section 5). It is not the same as cancelling one application
 * (3.1) and it is not Person deletion: it ends the engagement, settles the refund and cancels every application of it.
 *
 * Atomic, all three together: the service becomes Cancelled, the cancel refund is recorded (with its remark), and EVERY application
 * row of the service becomes Cancelled, whatever its status was, on Open, Done and Cancelled job orders alike. A row that is
 * already Cancelled is left as it is, and no row is ever deleted. The normal transition limits and the closed-order freeze do not
 * apply here. Nothing else changes: no payment, no fee, no Application Activity (excluded from V1).
 *
 * Only an In Progress service can be cancelled. A Complete one is refused: its way back is Hired -> Cancelled.
 *
 * One transaction on the connection's normal lock timeout, not retried (technical decisions: whole-service cancellation is not in
 * the retry scope). Locks, in the agreed order and without the job order level, because the cancellation never reads a job order
 * and must change rows of closed orders: the Person (L2, read even when deleted), the service (L3), then every one of its
 * applications in ascending id (L4), then the cancel remark (L5). Everything is checked again under the locks, so a payment, a
 * fee change, an Add Worker or a status update that ran a moment earlier is seen.
 */
class CancelJobSearchService
{
    /**
     * @param  numeric-string  $cancelRefund  the refund of the cancellation, never more than the money kept
     *
     * @throws ValidationException when the service is not In Progress any more, or the refund is more than the money kept
     */
    public function handle(ClientService $service, string $cancelRefund, int $userId): void
    {
        DB::transaction(function () use ($service, $cancelRefund, $userId): void {
            Client::withTrashed()->lockForUpdate()->findOrFail($service->client_id);

            $locked = ClientService::query()->lockForUpdate()->findOrFail($service->id);

            if ($locked->status !== ClientServiceStatus::InProgress) {
                throw ValidationException::withMessages(['status' => __('Only a service that is in progress can be cancelled.')]);
            }

            if (bccomp($cancelRefund, $locked->kept(), 2) === 1) {
                throw ValidationException::withMessages(['cancel_refund_amount' => __('The cancel refund cannot be more than the money kept.')]);
            }

            JobOrderWorker::query()
                ->where('client_service_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $locked->update([
                'status' => ClientServiceStatus::Cancel,
                'cancelled_at' => now(),
                'cancel_refund_amount' => $cancelRefund,
            ]);

            JobOrderWorker::query()
                ->where('client_service_id', $locked->id)
                ->where('case_status', '!=', WorkerCaseStatus::Cancelled->value)
                ->update(['case_status' => WorkerCaseStatus::Cancelled->value, 'updated_at' => now()]);

            $locked->remarks()->create([
                'kind' => RemarkKind::Cancel,
                'details' => [
                    'received' => $locked->received(),
                    'refund' => $locked->refund_amount,
                    'cancel_refund' => $locked->cancel_refund_amount,
                    'kept' => $locked->kept(),
                ],
                'created_by' => $userId,
            ]);
        });
    }
}
