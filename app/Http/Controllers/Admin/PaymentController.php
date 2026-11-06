<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\HandlesMoney;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PaymentController extends Controller
{
    use HandlesMoney;

    /**
     * Record a payment on a client's service. The ledger only grows: received money, remaining balance
     * and payment status are worked out from its rows.
     *
     * The service row is locked while the balance is checked and the payment saved, so two payments
     * sent together (a double click, two tabs) cannot both fit into the same remaining balance.
     */
    public function store(StorePaymentRequest $request, Client $client, ClientService $clientService): RedirectResponse
    {
        $amount = $this->money($request->validated('amount'));

        DB::transaction(function () use ($request, $clientService, $amount): void {
            $locked = ClientService::query()->lockForUpdate()->findOrFail($clientService->id);

            Gate::authorize('create', [Payment::class, $locked]);

            if (bccomp($amount, $locked->remaining(), 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => __('The payment cannot be more than the remaining balance.'),
                ]);
            }

            $locked->payments()->create([
                ...$request->validated(),
                'amount' => $amount,
                'created_by' => $request->user()->id,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return to_route('admin.clients.show', $client);
    }
}
