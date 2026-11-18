<?php

namespace App\Http\Controllers\Admin;

use App\Actions\JobSearch\CloseJobOrder;
use App\Actions\JobSearch\JobOrderChangedException;
use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseJobOrderRequest;
use App\Http\Requests\StoreJobOrderRequest;
use App\Http\Requests\UpdateJobOrderRequest;
use App\Http\Resources\JobOrderResource;
use App\Http\Resources\JobOrderWorkerResource;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobOrderController extends Controller
{
    /**
     * The longest search text that is used; anything after it is ignored.
     */
    private const SEARCH_MAX_LENGTH = 100;

    /**
     * List job orders, newest first, optionally filtered by company name and by status. The search text is cut to
     * 100 characters, and % and _ in it are searched for as themselves, not as wildcards. An unknown status is ignored.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', JobOrder::class);

        $search = mb_substr(trim($request->string('q')->toString()), 0, self::SEARCH_MAX_LENGTH);
        $status = JobOrderStatus::tryFrom($request->string('status')->toString());

        $jobOrders = JobOrder::query()
            ->withWorkerCounts()
            ->when($search !== '', fn ($query) => $query->whereRaw("company_name like ? escape '!'", ['%'.$this->escapeLike($search).'%']))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->latest()
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('admin/job-search/Index', [
            'jobOrders' => JobOrderResource::collection($jobOrders),
            'search' => $search,
            'filters' => (object) array_filter(['status' => $status?->value]),
        ]);
    }

    /**
     * The order and its workers, read-only. It works for a closed order too, which has no edit page: the workers of a
     * closed order can still be paid and followed. The operational list holds every application that is not Cancelled; the
     * cancelled ones are history and come apart, so they never mix with the counts. Deleted people are not listed.
     */
    public function show(JobOrder $jobOrder, JobOrderMoney $money): Response
    {
        Gate::authorize('view', $jobOrder);

        return Inertia::render('admin/job-search/Show', [
            'jobOrder' => JobOrderResource::make($jobOrder->loadCount(JobOrder::workerCounts())),
            'workers' => JobOrderWorkerResource::collection($this->workersOf($jobOrder)),
            'cancelledWorkers' => JobOrderWorkerResource::collection($this->workersOf($jobOrder, cancelled: true)),
            'workerFee' => Service::query()->where('key', Service::JOB_SEARCH_KEY)->value('default_fee'),
            // Live, never stored: the Kept Amount currently attributed to this order, also after it closed.
            'money' => $money->forOrder($jobOrder->id),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', JobOrder::class);

        return Inertia::render('admin/job-search/Create');
    }

    /**
     * Store a new job order. It is always open: the status is never taken from the request.
     */
    public function store(StoreJobOrderRequest $request): RedirectResponse
    {
        JobOrder::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job order created.')]);

        return to_route('admin.job-search.index');
    }

    /**
     * A closed order is frozen, so its edit page is refused as well.
     */
    public function edit(JobOrder $jobOrder): Response
    {
        Gate::authorize('update', $jobOrder);

        return Inertia::render('admin/job-search/Edit', [
            'jobOrder' => JobOrderResource::make($jobOrder->loadCount(JobOrder::workerCounts())),
            'workers' => JobOrderWorkerResource::collection($this->workersOf($jobOrder)),
            // The Job Search default fee prefills the Add worker dialog. It may be empty: the admin then types the fee.
            'workerFee' => Service::query()->where('key', Service::JOB_SEARCH_KEY)->value('default_fee'),
        ]);
    }

    public function update(UpdateJobOrderRequest $request, JobOrder $jobOrder): RedirectResponse
    {
        try {
            // The row is locked and checked again, so an edit that passed the policy just before another admin closed the order
            // cannot be saved onto the closed order.
            DB::transaction(function () use ($request, $jobOrder): void {
                $locked = JobOrder::query()->lockForUpdate()->findOrFail($jobOrder->id);

                if ($locked->isClosed()) {
                    throw new DomainException('This job order is already closed.');
                }

                $locked->update($request->validated());
            });
        } catch (DomainException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            return to_route('admin.job-search.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job order updated.')]);

        return to_route('admin.job-search.index');
    }

    /**
     * Close the order as done or cancelled. Both close it for good: no new worker can be added, and it cannot be
     * reopened or edited. Open applications are resolved first, all in one transaction (CloseJobOrder): deleted people's
     * open applications are cancelled automatically, and the admin's resolutions are applied for everyone else. Payments are
     * not touched. If the order was closed by someone else a moment ago, or the open applications are not what the admin's
     * screen showed, nothing changes and the admin is told.
     */
    public function close(CloseJobOrderRequest $request, JobOrder $jobOrder, CloseJobOrder $closeJobOrder): RedirectResponse
    {
        $status = JobOrderStatus::from($request->validated('status'));

        try {
            $closeJobOrder->handle($jobOrder, $status, $request->resolutions(), $request->expectedOpen(), $request->expectedDeletedOpen());
        } catch (JobOrderChangedException $exception) {
            // The order is still open: stay on the page so the admin can look at the current state.
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            return back();
        } catch (DomainException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            return $this->afterClose($request);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $status === JobOrderStatus::Done
            ? __('Job order marked as done.')
            : __('Job order cancelled.')]);

        return $this->afterClose($request);
    }

    /**
     * What closing this order would involve, for the close dialog: the open (Applied or Interview) applications of people who
     * are not deleted, which the admin must resolve, and the ids of the open applications of deleted people, which the close
     * cancels by itself. The close compares the admin's screen with the real state again under locks.
     */
    public function closePreview(JobOrder $jobOrder): JsonResponse
    {
        Gate::authorize('close', $jobOrder);

        [$deleted, $live] = $jobOrder->workers()->open()->with('client')->orderBy('id')->get()
            ->partition(fn (JobOrderWorker $worker) => $worker->client->trashed());

        return response()->json([
            'open' => $live->map(fn (JobOrderWorker $worker) => [
                'worker_id' => $worker->id,
                'name' => $worker->client->name,
                'phone' => $worker->client->phone,
                'status' => $worker->case_status->value,
            ])->values(),
            'deleted_open' => $deleted->pluck('id')->values(),
        ]);
    }

    /**
     * Back to the page the admin came from. From the edit page that is the list, because a closed order's edit page is refused.
     */
    private function afterClose(CloseJobOrderRequest $request): RedirectResponse
    {
        return $request->validated('from') === 'edit' ? to_route('admin.job-search.index') : back();
    }

    /**
     * The workers of an order that are shown, newest first, whose person is not deleted: the operational ones (Applied, Interview,
     * Hired, Rejected), or with $cancelled the historical cancelled applications. Loaded with their person, record and payments
     * in three queries.
     *
     * @return Collection<int, JobOrderWorker>
     */
    private function workersOf(JobOrder $jobOrder, bool $cancelled = false): Collection
    {
        return $jobOrder->workers()
            ->operational()
            ->where('case_status', $cancelled ? '=' : '!=', WorkerCaseStatus::Cancelled->value)
            ->with(['client', 'clientService.payments', 'clientService.client'])
            ->latest('id')
            ->get();
    }

    /**
     * Make % and _ match themselves in a LIKE pattern. "!" is the escape character because, unlike a
     * backslash, it means the same in MySQL and SQLite.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
