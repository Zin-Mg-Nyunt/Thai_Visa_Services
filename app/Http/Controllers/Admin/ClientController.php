<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
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
        ]);
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
}
