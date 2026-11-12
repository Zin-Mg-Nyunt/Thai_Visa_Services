<?php

namespace App\Http\Controllers\Admin;

use App\Enums\JobOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseJobOrderRequest;
use App\Http\Requests\StoreJobOrderRequest;
use App\Http\Requests\UpdateJobOrderRequest;
use App\Http\Resources\JobOrderResource;
use App\Models\JobOrder;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->withCount('workers')
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
            'jobOrder' => JobOrderResource::make($jobOrder->loadCount('workers')),
        ]);
    }

    public function update(UpdateJobOrderRequest $request, JobOrder $jobOrder): RedirectResponse
    {
        $jobOrder->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job order updated.')]);

        return to_route('admin.job-search.index');
    }

    /**
     * Close the order as done or cancelled. Both close it for good: no new worker can be added, and it cannot be
     * reopened or edited. The workers and their payments are not touched. If the order was closed by someone else a
     * moment ago, nothing changes and the admin is told.
     */
    public function close(CloseJobOrderRequest $request, JobOrder $jobOrder): RedirectResponse
    {
        $status = JobOrderStatus::from($request->validated('status'));

        try {
            $jobOrder->close($status);
        } catch (DomainException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This job order is already closed.')]);

            return $this->afterClose($request);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $status === JobOrderStatus::Done
            ? __('Job order marked as done.')
            : __('Job order cancelled.')]);

        return $this->afterClose($request);
    }

    /**
     * Back to the page the admin came from. From the edit page that is the list, because a closed order's edit page is refused.
     */
    private function afterClose(CloseJobOrderRequest $request): RedirectResponse
    {
        return $request->validated('from') === 'edit' ? to_route('admin.job-search.index') : back();
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
