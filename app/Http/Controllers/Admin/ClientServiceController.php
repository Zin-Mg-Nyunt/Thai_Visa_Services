<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\ServiceAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientServiceRequest;
use App\Http\Requests\UpdateClientServiceRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ClientServiceResource;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientServiceController extends Controller
{
    /**
     * Show the form for adding a service to a client.
     */
    public function create(Client $client): Response
    {
        Gate::authorize('create', ClientService::class);

        return Inertia::render('admin/clients/services/Create', [
            'client' => ClientResource::make($client),
            'serviceOptions' => $this->serviceOptions(),
        ]);
    }

    /**
     * Add a service to a client. If the client already paid something, it is recorded as the first payment (cash, today).
     */
    public function store(StoreClientServiceRequest $request, Client $client): RedirectResponse
    {
        $received = number_format((float) ($request->validated('received_now') ?? 0), 2, '.', '');

        DB::transaction(function () use ($request, $client, $received): void {
            $clientService = $client->clientServices()->create($request->safe()->except('received_now'));

            if (bccomp($received, '0', 2) === 1) {
                $clientService->payments()->create([
                    'amount' => $received,
                    'paid_at' => today(),
                    'method' => PaymentMethod::Cash,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service added.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Show the form for editing a client's service. The service itself stays fixed; only its sub-service and details change.
     * The service's default fee is sent so the form can fill the fee when the sub-service changes to one without a fee.
     */
    public function edit(Client $client, ClientService $clientService): Response
    {
        Gate::authorize('update', $clientService);

        $clientService->load(['service', 'subService']);

        return Inertia::render('admin/clients/services/Edit', [
            'client' => ClientResource::make($client),
            'clientService' => ClientServiceResource::make($clientService),
            'subServiceOptions' => $this->subServiceOptions($clientService),
            'serviceDefaultFee' => $clientService->service->default_fee,
        ]);
    }

    /**
     * Update a client's service.
     */
    public function update(UpdateClientServiceRequest $request, Client $client, ClientService $clientService): RedirectResponse
    {
        $clientService->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service updated.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * The services that can be added: active main services other than Job Search, each with its active
     * sub-services. Fees are decimal strings; the form prefills the sub-service fee, else the service fee.
     *
     * @return array<int, array{id: int, title: string|null, title_my: string|null, default_fee: string|null, sub_services: array<int, array{id: int, title: string|null, title_my: string|null, default_fee: string|null}>}>
     */
    private function serviceOptions(): array
    {
        return Service::main()
            ->whereNull('key')
            ->where('status', ServiceAvailability::Active)
            ->with(['children' => fn ($query) => $query->where('status', ServiceAvailability::Active)->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(fn (Service $service) => [
                ...$this->option($service),
                'sub_services' => $service->children->map(fn (Service $sub) => $this->option($sub))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The sub-services this record can switch between: the service's active sub-services plus the one it already has,
     * even if that one was made inactive or deleted since, so the record can still be saved as it is.
     *
     * @return array<int, array{id: int, title: string|null, title_my: string|null, default_fee: string|null}>
     */
    private function subServiceOptions(ClientService $clientService): array
    {
        return Service::withTrashed()
            ->where('parent_id', $clientService->service_id)
            ->where(function ($query) use ($clientService): void {
                $query->where(fn ($available) => $available
                    ->where('status', ServiceAvailability::Active)
                    ->whereNull('deleted_at'));

                if ($clientService->sub_service_id !== null) {
                    $query->orWhere('id', $clientService->sub_service_id);
                }
            })
            ->orderBy('id')
            ->get()
            ->map(fn (Service $sub) => $this->option($sub))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, title: string|null, title_my: string|null, default_fee: string|null}
     */
    private function option(Service $service): array
    {
        return [
            'id' => $service->id,
            'title' => $service->title,
            'title_my' => $service->title_my,
            'default_fee' => $service->default_fee,
        ];
    }
}
