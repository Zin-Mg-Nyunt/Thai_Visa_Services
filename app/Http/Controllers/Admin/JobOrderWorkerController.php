<?php

namespace App\Http\Controllers\Admin;

use App\Actions\JobSearch\AddWorker;
use App\Actions\JobSearch\CancelHiredApplication;
use App\Actions\JobSearch\FindEngagement;
use App\Actions\JobSearch\UpdateApplicationStatuses;
use App\Concerns\HandlesMoney;
use App\Enums\EngagementState;
use App\Enums\WorkerCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelHiredApplicationRequest;
use App\Http\Requests\LookupJobOrderWorkerRequest;
use App\Http\Requests\StoreJobOrderWorkerRequest;
use App\Http\Requests\UpdateApplicationStatusesRequest;
use App\Models\Client;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class JobOrderWorkerController extends Controller
{
    use HandlesMoney;

    /**
     * Look a phone number up before adding a worker: the matching active person, if there is one, and whether they are
     * already on this order. Soft-deleted people are not found (their phone number can be used again). It is a POST so the
     * phone number stays out of the address, the browser history and the access logs; it changes nothing.
     */
    public function lookup(LookupJobOrderWorkerRequest $request, JobOrder $jobOrder, FindEngagement $findEngagement): JsonResponse
    {
        $phone = $request->validated('phone');
        $client = Client::query()->where('phone', $phone)->first();

        return response()->json([
            'phone' => $phone,
            'client' => $client === null ? null : [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'birthday' => $client->birthday->toDateString(),
                'gender' => $client->gender->value,
            ],
            'on_this_order' => $client !== null && $jobOrder->workers()->where('client_id', $client->id)->whereIn('case_status', WorkerCaseStatus::activeValues())->exists(),
            'engagement' => $client === null ? null : $this->engagementOf($client, $findEngagement),
        ]);
    }

    /**
     * What adding this person would do with their In Progress Job Search record, for the dialog: null when they have none
     * (a new record is created). A reusable record also sends its fee and what was received, so the admin can keep or change
     * the fee. Nothing is locked: this only informs; Add Worker decides again under locks.
     *
     * @return array{state: string, fee?: string, received?: string}|null
     */
    private function engagementOf(Client $client, FindEngagement $findEngagement): ?array
    {
        $jobSearch = Service::query()->where('key', Service::JOB_SEARCH_KEY)->first();

        if ($jobSearch === null) {
            return null;
        }

        $check = $findEngagement->for($client, $jobSearch);

        return match ($check->state) {
            EngagementState::None => null,
            EngagementState::Reusable => [
                'state' => $check->state->value,
                'fee' => $check->record->fee,
                'received' => $check->record->received(),
            ],
            default => ['state' => $check->state->value],
        };
    }

    /**
     * Add a worker: an existing person is reused, a new one is created, and the worker gets a Job Search record with
     * the fee. More workers than the company asked for is allowed; it is only a warning on the pages.
     */
    public function store(StoreJobOrderWorkerRequest $request, JobOrder $jobOrder, AddWorker $addWorker): RedirectResponse
    {
        $data = $request->validated();

        try {
            $addWorker->handle(
                $jobOrder,
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                Arr::only($data, ['name', 'phone', 'birthday', 'gender']),
                $this->money($data['fee']),
                $request->user()?->id,
            );
        } catch (DomainException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            // A closed order has no edit page, so the admin goes to the list.
            return $jobOrder->fresh()?->isClosed()
                ? to_route('admin.job-search.index')
                : back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Worker added.')]);

        return back();
    }

    /**
     * Change the status of one or several applications of an open order, all or nothing. If anything is not allowed (a
     * transition the rules refuse, a deleted person, a Job Search record that is no longer in progress, or an application
     * another admin already changed), nothing changes and the admin is told why.
     */
    public function updateStatus(UpdateApplicationStatusesRequest $request, JobOrder $jobOrder, UpdateApplicationStatuses $update): RedirectResponse
    {
        try {
            $update->handle($jobOrder, $request->statusChanges());
        } catch (DomainException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status updated.')]);

        return back();
    }

    /**
     * Hired to Cancelled for one application, also on a closed order (which stays closed). It is its own workflow: the normal
     * status update and the job order close never allow it. The application and its Job Search record change together or not
     * at all. Nothing is refused silently: the admin is told why.
     */
    public function cancelHired(CancelHiredApplicationRequest $request, JobOrder $jobOrder, JobOrderWorker $worker, CancelHiredApplication $cancel): RedirectResponse
    {
        abort_unless($worker->job_order_id === $jobOrder->id, 404);

        try {
            $cancel->handle($worker);
        } catch (DomainException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The Hired application was cancelled. The Job Search service is In Progress again.')]);

        return back();
    }
}
