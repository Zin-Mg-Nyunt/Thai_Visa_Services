<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    /**
     * List the main services; their sub-services are shown on each service's own page.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Service::class);

        $services = Service::main()->withCount('children')->orderBy('id')->get();

        return Inertia::render('admin/services/Index', [
            'services' => ServiceResource::collection($services),
        ]);
    }

    /**
     * Show the form for creating a service, or a sub-service when ?parent= names a main service.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Service::class);

        $parentOptions = $this->parentOptions();

        return Inertia::render('admin/services/Create', [
            'parentOptions' => $parentOptions,
            'defaultParentId' => collect($parentOptions)->pluck('id')->contains($request->integer('parent'))
                ? $request->integer('parent')
                : null,
        ]);
    }

    /**
     * Store a new service or sub-service.
     */
    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $service = Service::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service created.')]);

        return to_route('admin.services.show', $service);
    }

    /**
     * Show a service with its details and, for a main service, its sub-services.
     */
    public function show(Service $service): Response
    {
        Gate::authorize('view', $service);

        $subServices = $service->isSubService() ? collect() : $service->children()->orderBy('id')->get();

        return Inertia::render('admin/services/Show', [
            'service' => ServiceResource::make($service->load('parent')),
            'subServices' => ServiceResource::collection($subServices),
            'canHaveSubServices' => $this->canHaveSubServices($service),
            'canDelete' => Gate::allows('delete', $service),
        ]);
    }

    /**
     * Show the form for editing a service or sub-service.
     */
    public function edit(Service $service): Response
    {
        Gate::authorize('update', $service);

        $canBeSubService = ! $service->isJobSearch() && ($service->isSubService() || ! $service->children()->withTrashed()->exists());

        return Inertia::render('admin/services/Edit', [
            'service' => ServiceResource::make($service),
            'parentOptions' => $canBeSubService ? $this->parentOptions($service) : [],
        ]);
    }

    /**
     * Update a service or sub-service.
     */
    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service updated.')]);

        return to_route('admin.services.show', $service);
    }

    /**
     * Delete a service or sub-service. It is a soft delete, so clients, payments and reports keep their history.
     * The sub-services of a deleted main service stay in the database and can no longer be chosen.
     */
    public function destroy(Service $service): RedirectResponse
    {
        Gate::authorize('delete', $service);

        $parentId = $service->parent_id;

        $service->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service deleted.')]);

        return $parentId === null
            ? to_route('admin.services.index')
            : to_route('admin.services.show', $parentId);
    }

    /**
     * The main services a service can be placed under: every main service except Job Search and the service itself.
     *
     * @return array<int, array{id: int, title: string|null, title_my: string|null}>
     */
    private function parentOptions(?Service $except = null): array
    {
        return Service::main()
            ->whereNull('key')
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->orderBy('id')
            ->get(['id', 'title', 'title_my'])
            ->map(fn (Service $service) => ['id' => $service->id, 'title' => $service->title, 'title_my' => $service->title_my])
            ->all();
    }

    private function canHaveSubServices(Service $service): bool
    {
        return ! $service->isSubService() && ! $service->isJobSearch();
    }
}
