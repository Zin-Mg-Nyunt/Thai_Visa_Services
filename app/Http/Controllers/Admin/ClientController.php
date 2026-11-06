<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Client\TrackingPinGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteClientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientPinAttemptResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ClientServiceResource;
use App\Models\Client;
use App\Models\ClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * List clients, newest first, optionally filtered by name or phone number. The search text is cut to
     * 100 characters, and % and _ in it are searched for as themselves, not as wildcards.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $search = mb_substr(trim($request->string('q')->toString()), 0, self::SEARCH_MAX_LENGTH);
        $phoneSearch = Client::normalisePhone($search);

        $clients = Client::query()
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
        ]);
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
        $client->delete();

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
