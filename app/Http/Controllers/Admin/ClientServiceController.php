<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\HandlesMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\RemarkKind;
use App\Enums\ServiceAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelClientServiceRequest;
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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientServiceController extends Controller
{
    use HandlesMoney;

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
     * Add a service to a client. If the client already paid something, it is recorded as the first payment:
     * cash, dated today in the agency's time zone (Bangkok), not UTC. A remark becomes the first in its list.
     */
    public function store(StoreClientServiceRequest $request, Client $client): RedirectResponse
    {
        $received = $this->money($request->validated('received_now'));

        DB::transaction(function () use ($request, $client, $received): void {
            $clientService = $client->clientServices()->create($request->safe()->except(['received_now', 'remark']));
            $this->addRemark($clientService, $request->validated('remark'), $request->user()->id);

            if (bccomp($received, '0', 2) === 1) {
                $clientService->payments()->create([
                    'amount' => $received,
                    'paid_at' => today(config('app.display_timezone'))->toDateString(),
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

        $clientService->load(['service', 'subService', 'remarks' => fn ($query) => $query->latest('id')->with('creator:id,name')]);

        return Inertia::render('admin/clients/services/Edit', [
            'client' => ClientResource::make($client),
            'clientService' => ClientServiceResource::make($clientService),
            'subServiceOptions' => $this->subServiceOptions($clientService),
            'serviceDefaultFee' => $clientService->service->default_fee,
            'received' => $clientService->received(),
        ]);
    }

    /**
     * Update a client's service. A new remark is added to its list.
     *
     * The fee may go below the money already received. The extra is then the refund, and a refund note is added
     * to the remarks, for example fee 8,000 to 4,000 after 5,000 received leaves 1,000 to refund. The refund is
     * worked out again on every save, so raising the fee back lowers or clears it, with another note.
     * A cancelled service keeps the refund set when it was cancelled.
     */
    public function update(UpdateClientServiceRequest $request, Client $client, ClientService $clientService): RedirectResponse
    {
        $userId = $request->user()->id;

        // The row is locked so a payment saved at the same moment cannot change the money received under the check.
        $refund = DB::transaction(function () use ($request, $clientService, $userId): ?string {
            $locked = ClientService::query()->lockForUpdate()->findOrFail($clientService->id);
            $details = $request->safe()->except('remark');
            $refund = null;

            if (! $locked->isCancelled()) {
                $oldFee = $locked->fee;
                $newFee = $this->money($details['fee']);
                $refund = $locked->refundForFee($newFee);

                if (bccomp($refund, $locked->refund_amount, 2) !== 0) {
                    $details['refund_amount'] = $refund;

                    $locked->remarks()->create([
                        'kind' => RemarkKind::Refund,
                        'details' => ['from_fee' => $oldFee, 'to_fee' => $newFee, 'received' => $locked->received(), 'refund' => $refund],
                        'created_by' => $userId,
                    ]);
                }
            }

            $locked->update($details);
            $this->addRemark($locked, $request->validated('remark'), $userId);

            return $refund;
        });

        Inertia::flash('toast', $refund !== null && bccomp($refund, '0', 2) === 1
            ? ['type' => 'warning', 'message' => __('Service updated. The fee is below the money received, so the extra is recorded as a refund.')]
            : ['type' => 'success', 'message' => __('Service updated.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Cancel a client's service. Cancel is final and is not delete: the record stays as history with Cancel status.
     * The cancel refund is kept apart from the refund of fee changes, and both are taken off what the agency keeps,
     * so it cannot be more than the money still kept. Nothing more is owed, and a cancel note records the numbers.
     */
    public function cancel(CancelClientServiceRequest $request, Client $client, ClientService $clientService): RedirectResponse
    {
        $cancelRefund = $this->money($request->validated('cancel_refund_amount'));
        $userId = $request->user()->id;

        // The row is locked so a double click or a payment sent at the same moment cannot slip past the checks.
        DB::transaction(function () use ($clientService, $cancelRefund, $userId): void {
            $locked = ClientService::query()->lockForUpdate()->findOrFail($clientService->id);

            if ($locked->status !== ClientServiceStatus::InProgress) {
                throw ValidationException::withMessages(['status' => __('Only a service that is in progress can be cancelled.')]);
            }

            if (bccomp($cancelRefund, $locked->kept(), 2) === 1) {
                throw ValidationException::withMessages(['cancel_refund_amount' => __('The cancel refund cannot be more than the money kept.')]);
            }

            $locked->update([
                'status' => ClientServiceStatus::Cancel,
                'cancelled_at' => now(),
                'cancel_refund_amount' => $cancelRefund,
            ]);

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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service cancelled.')]);

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

    /**
     * Add an admin's note to the service's remarks. An empty remark adds nothing.
     */
    private function addRemark(ClientService $clientService, ?string $remark, int $userId): void
    {
        if ($remark === null || trim($remark) === '') {
            return;
        }

        $clientService->remarks()->create([
            'kind' => RemarkKind::Note,
            'body' => $remark,
            'created_by' => $userId,
        ]);
    }
}
