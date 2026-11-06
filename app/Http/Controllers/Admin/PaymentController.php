<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Client;
use App\Models\ClientService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PaymentController extends Controller
{
    /**
     * Record a payment on a client's service. The ledger only grows: received money, remaining balance
     * and payment status are worked out from its rows.
     */
    public function store(StorePaymentRequest $request, Client $client, ClientService $clientService): RedirectResponse
    {
        $clientService->payments()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return to_route('admin.clients.show', $client);
    }
}
