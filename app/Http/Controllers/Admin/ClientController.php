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
     * List clients, newest first, optionally filtered by name or phone number.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $search = trim($request->string('q')->toString());
        $phoneSearch = Client::normalisePhone($search);

        $clients = Client::query()
            ->when($search !== '', function ($query) use ($search, $phoneSearch) {
                $query->where(function ($query) use ($search, $phoneSearch) {
                    $query->where('name', 'like', "%{$search}%");

                    if (preg_match('/\d/', $phoneSearch) === 1) {
                        $query->orWhere('phone', 'like', "%{$phoneSearch}%");
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
        $client = Client::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client created.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Show a client.
     */
    public function show(Client $client): Response
    {
        Gate::authorize('view', $client);

        return Inertia::render('admin/clients/Show', [
            'client' => ClientResource::make($client),
            'trackingUrl' => $client->trackingUrl(),
            'photoUrl' => $client->photoUrl(),
            'lockedMinutes' => (int) ceil((new TrackingPinGuard($client))->lockedForSeconds() / 60),
            'clientServices' => ClientServiceResource::collection(
                $client->clientServices()->with(['service', 'subService'])->latest()->latest('id')->get(),
            ),
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
}
