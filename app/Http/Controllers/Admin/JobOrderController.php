<?php

namespace App\Http\Controllers\Admin;

use App\Enums\JobOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobOrderRequest;
use App\Http\Requests\UpdateJobOrderRequest;
use App\Http\Resources\JobOrderResource;
use App\Models\JobOrder;
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
     * Make % and _ match themselves in a LIKE pattern. "!" is the escape character because, unlike a
     * backslash, it means the same in MySQL and SQLite.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
