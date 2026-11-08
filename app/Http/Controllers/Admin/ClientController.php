<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Client\TrackingPinGuard;
use App\Enums\ClientServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteClientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientPinAttemptResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ClientServiceResource;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * How many of the latest wrong PINs the detail page lists.
     */
    private const PIN_ATTEMPTS_SHOWN = 15;

    /**
     * The longest search text that is used; anything after it is ignored.
     */
    private const SEARCH_MAX_LENGTH = 100;

    /**
     * The payment filters of the clients list: fully paid, or a balance still remaining.
     *
     * @var array<int, string>
     */
    private const PAYMENT_FILTERS = ['fully_paid', 'remaining'];

    /**
     * List clients, newest first, optionally filtered by name or phone number. The search text is cut to
     * 100 characters, and % and _ in it are searched for as themselves, not as wildcards.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $search = mb_substr(trim($request->string('q')->toString()), 0, self::SEARCH_MAX_LENGTH);
        $phoneSearch = Client::normalisePhone($search);

        $filters = $this->serviceFilters($request);

        $clients = Client::query()
            ->when($filters !== [], function ($query) use ($filters) {
                $service = $filters['service'] ?? null;
                $subService = $filters['sub_service'] ?? null;
                $status = $filters['status'] ?? null;
                $payment = $filters['payment'] ?? null;

                $query->whereHas('clientServices', function ($query) use ($service, $subService, $status, $payment) {
                    $query
                        ->when($service !== null, fn ($query) => $query->where('service_id', $service))
                        ->when($subService === 'none', fn ($query) => $query->whereNull('sub_service_id'))
                        ->when(is_int($subService), fn ($query) => $query->where('sub_service_id', $subService))
                        ->when($status !== null, fn ($query) => $query->where('status', $status))
                        ->when($payment !== null, fn ($query) => $this->wherePayment($query, $payment));
                });
            })
            ->when($search !== '', function ($query) use ($search, $phoneSearch) {
                $query->where(function ($query) use ($search, $phoneSearch) {
                    $query->whereRaw("name like ? escape '!'", ['%'.$this->escapeLike($search).'%']);

                    if (preg_match('/\d/', $phoneSearch) === 1) {
                        $query->orWhereRaw("phone like ? escape '!'", ['%'.$this->escapeLike($phoneSearch).'%']);
                    }
                });
            })
            ->latest()
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('admin/clients/Index', [
            'clients' => ClientResource::collection($clients),
            'search' => $search,
            'filters' => (object) $filters,
            'serviceOptions' => $this->serviceFilterOptions(),
        ]);
    }

    /**
     * The service filters in the address, cleaned up: a main service id, a sub-service id or "none" (main service
     * only), and a client service status. Anything else is ignored. A sub-service counts only with its own main
     * service, so the page's drop-downs always show the filter that is applied. A client matches when ONE of their
     * services fits every chosen filter.
     *
     * @return array{service?: int, sub_service?: int|string, status?: string, payment?: string}
     */
    private function serviceFilters(Request $request): array
    {
        $filters = [];

        if ($request->integer('service') > 0) {
            $filters['service'] = $request->integer('service');
        }

        $subService = $request->integer('sub_service');

        if (isset($filters['service']) && $request->string('sub_service')->toString() === 'none') {
            $filters['sub_service'] = 'none';
        } elseif (isset($filters['service']) && $subService > 0
            && Service::withTrashed()->whereKey($subService)->where('parent_id', $filters['service'])->exists()) {
            $filters['sub_service'] = $subService;
        }

        $status = ClientServiceStatus::tryFrom($request->string('status')->toString());

        if ($status !== null) {
            $filters['status'] = $status->value;
        }

        if (in_array($request->string('payment')->toString(), self::PAYMENT_FILTERS, true)) {
            $filters['payment'] = $request->string('payment')->toString();
        }

        return $filters;
    }

    /**
     * Keep the client services by what has been paid, worked out in the database in whole satang (exact, never a float).
     * Fully paid: something is paid and all of the fee is. Remaining: a balance is left. A cancelled service owes nothing
     * and its payment status is not shown, so it is in neither. This is the same rule as ClientService::paymentStatus() and
     * ClientService::remaining().
     *
     * @param  Builder<ClientService>  $query
     * @return Builder<ClientService>
     */
    private function wherePayment($query, string $payment)
    {
        $paid = '(select coalesce(sum(round(payments.amount * 100)), 0) from payments where payments.client_service_id = client_services.id)';

        return $query
            ->where('client_services.status', '!=', ClientServiceStatus::Cancel->value)
            ->whereRaw($payment === 'fully_paid'
                ? "{$paid} > 0 and {$paid} >= round(client_services.fee * 100)"
                : "{$paid} < round(client_services.fee * 100)");
    }

    /**
     * The main services, each with its sub-services, for the filter drop-downs.
     *
     * @return array<int, array{id: int, title: string|null, title_my: string|null, sub_services: array<int, array{id: int, title: string|null, title_my: string|null}>}>
     */
    private function serviceFilterOptions(): array
    {
        return Service::main()
            ->with(['children' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'title' => $service->title,
                'title_my' => $service->title_my,
                'sub_services' => $service->children
                    ->map(fn (Service $sub) => ['id' => $sub->id, 'title' => $sub->title, 'title_my' => $sub->title_my])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Show the form for creating a client.
     */
    public function create(): Response
    {
        Gate::authorize('create', Client::class);

        return Inertia::render('admin/clients/Create');
    }

    /**
     * Store a new client.
     */
    public function store(StoreClientRequest $request): RedirectResponse
    {
        $client = Client::create($request->safe()->except('photo'));

        if ($request->hasFile('photo')) {
            $client->replacePhoto($request->file('photo'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client created.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Show a client.
     */
    public function show(Client $client): Response
    {
        Gate::authorize('view', $client);

        $clientServices = $client->clientServices()
            ->with([
                'service',
                'subService',
                'payments' => fn ($query) => $query->latest('paid_at')->latest('id'),
                'documents' => fn ($query) => $query->latest('id'),
                'remarks' => fn ($query) => $query->latest('id')->with('creator:id,name'),
            ])
            ->latest()
            ->latest('id')
            ->get();

        return Inertia::render('admin/clients/Show', [
            'client' => ClientResource::make($client),
            'trackingUrl' => $client->trackingUrl(),
            'photoUrl' => $client->photoUrl(),
            'lockedMinutes' => (int) ceil((new TrackingPinGuard($client))->lockedForSeconds() / 60),
            'clientServices' => ClientServiceResource::collection($clientServices),
            'totals' => [
                // Net of refunds: what the agency keeps from this client.
                'received' => $clientServices->reduce(fn (string $total, ClientService $item) => bcadd($total, $item->kept(), 2), '0.00'),
                'remaining' => $clientServices->reduce(fn (string $total, ClientService $item) => bcadd($total, $item->remaining(), 2), '0.00'),
            ],
            'pinAttempts' => ClientPinAttemptResource::collection($client->pinAttempts()->limit(self::PIN_ATTEMPTS_SHOWN)->get()),
        ]);
    }

    /**
     * Lift a PIN lock early, for example when the real client was locked out by someone else's wrong tries.
     * The audit log is kept.
     */
    public function unlock(Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        (new TrackingPinGuard($client))->reset();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client link unlocked.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Show the form for editing a client.
     */
    public function edit(Client $client): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('admin/clients/Edit', [
            'client' => ClientResource::make($client),
        ]);
    }

    /**
     * Update a client.
     */
    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client updated.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Delete a client after the admin has typed the client's phone number.
     * The record is soft-deleted so reports keep their history.
     */
    public function destroy(DeleteClientRequest $request, Client $client): RedirectResponse
    {
        // One transaction: the client and their documents go together or not at all. The files are removed only after
        // it commits, so a delete that fails part-way leaves every record and file in place.
        DB::transaction(fn () => $client->delete());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client deleted.')]);

        return to_route('admin.clients.index');
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
