<?php

use App\Enums\ClientServiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function paymentInput(array $overrides = []): array
{
    return [
        'amount' => '1000.00',
        'paid_at' => today()->toDateString(),
        'method' => 'cash',
        'note' => null,
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->record = ClientService::factory()->create(['client_id' => $this->client->id, 'fee' => 5000]);
    $this->url = route('admin.clients.services.payments.store', [$this->client, $this->record]);
});

describe('payment status', function () {
    test('is unpaid, deposit or fully paid by what is received', function (array $amounts, string $fee, PaymentStatus $status, string $received, string $remaining) {
        $record = ClientService::factory()->create(['fee' => $fee]);
        foreach ($amounts as $amount) {
            Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $amount]);
        }
        $record->refresh();

        expect($record->paymentStatus())->toBe($status)
            ->and($record->received())->toBe($received)
            ->and($record->remaining())->toBe($remaining);
    })->with([
        'nothing paid' => [[], '5000', PaymentStatus::Unpaid, '0.00', '5000.00'],
        'part paid is a deposit' => [['1000'], '5000', PaymentStatus::Deposit, '1000.00', '4000.00'],
        'two payments still a deposit' => [['1000', '2500.50'], '5000', PaymentStatus::Deposit, '3500.50', '1499.50'],
        'everything in one go is fully paid' => [['5000'], '5000', PaymentStatus::FullyPaid, '5000.00', '0.00'],
        'a deposit completed later is fully paid' => [['1000', '4000'], '5000', PaymentStatus::FullyPaid, '5000.00', '0.00'],
        'a zero fee with nothing received is unpaid' => [[], '0', PaymentStatus::Unpaid, '0.00', '0.00'],
        'pennies add up exactly' => [['0.10', '0.20'], '0.30', PaymentStatus::FullyPaid, '0.30', '0.00'],
        'received above a lowered fee shows no negative balance' => [['6000'], '5000', PaymentStatus::FullyPaid, '6000.00', '0.00'],
    ]);
});

describe('recording a payment', function () {
    test('guests are redirected and non-superadmins are refused', function () {
        $this->post($this->url, paymentInput())->assertRedirect(route('login'));

        DB::table('users')->where('id', $this->admin->id)->update(['role' => 'staff']);
        $this->actingAs($this->admin->fresh())->post($this->url, paymentInput())->assertForbidden();

        expect(Payment::count())->toBe(0);
    });

    test('saves the payment with who recorded it', function () {
        $this->actingAs($this->admin)
            ->post($this->url, paymentInput(['amount' => '1500', 'note' => 'First deposit']))
            ->assertRedirect(route('admin.clients.show', $this->client));

        $payment = Payment::sole();

        expect($payment->client_service_id)->toBe($this->record->id)
            ->and($payment->amount)->toBe('1500.00')
            ->and($payment->created_by)->toBe($this->admin->id)
            ->and($payment->note)->toBe('First deposit')
            ->and($this->record->refresh()->paymentStatus())->toBe(PaymentStatus::Deposit);
    });

    test('accepts exactly the remaining balance and then becomes fully paid', function () {
        $this->actingAs($this->admin)->post($this->url, paymentInput(['amount' => '2000']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url, paymentInput(['amount' => '3000']))->assertSessionHasNoErrors();

        expect($this->record->refresh()->paymentStatus())->toBe(PaymentStatus::FullyPaid);
    });

    test('rejects invalid payments', function (array $input, string $field) {
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 4000]);

        $this->actingAs($this->admin)->post($this->url, paymentInput($input))->assertSessionHasErrors($field);

        expect(Payment::count())->toBe(1);
    })->with([
        'more than the remaining balance' => [['amount' => '1000.01'], 'amount'],
        'zero' => [['amount' => '0'], 'amount'],
        'negative' => [['amount' => '-5'], 'amount'],
        'three decimals' => [['amount' => '1.234'], 'amount'],
        'missing amount' => [['amount' => null], 'amount'],
        'not a number' => [['amount' => 'abc'], 'amount'],
        'future date' => [['paid_at' => '2999-01-01'], 'paid_at'],
        'missing date' => [['paid_at' => null], 'paid_at'],
        'unknown method' => [['method' => 'bitcoin'], 'method'],
        'long note' => [['note' => str_repeat('a', 501)], 'note'],
    ]);

    test('is not reachable through another client', function () {
        $other = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.payments.store', [$other, $this->record]), paymentInput())
            ->assertNotFound();

        expect(Payment::count())->toBe(0);
    });

    test('a cancelled service takes no payment', function () {
        $this->record->update(['status' => ClientServiceStatus::Cancel]);

        $this->actingAs($this->admin)->post($this->url, paymentInput())->assertForbidden();

        expect(Payment::count())->toBe(0);
    });

    test('a payment fills the balance left after earlier payments, never more', function () {
        $this->actingAs($this->admin)->post($this->url, paymentInput(['amount' => '4000']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url, paymentInput(['amount' => '1000.01']))->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post($this->url, paymentInput(['amount' => '1000']))->assertSessionHasNoErrors();

        expect($this->record->refresh()->received())->toBe('5000.00');
    });

    test('today is the agency\'s date in Bangkok, not the UTC date', function () {
        // 20:00 UTC on 6 November is 03:00 on 7 November in Bangkok.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 20:00:00', 'UTC'));

        $this->actingAs($this->admin)->post($this->url, paymentInput(['paid_at' => '2026-11-07']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url, paymentInput(['paid_at' => '2026-11-08']))->assertSessionHasErrors('paid_at');

        expect(Payment::sole()->paid_at->toDateString())->toBe('2026-11-07');
    });
});

describe('first payment when adding a service', function () {
    beforeEach(function () {
        $this->service = Service::factory()->create();
        $this->input = fn (array $overrides = []) => [
            'service_id' => $this->service->id,
            'sub_service_id' => null,
            'status' => 'in_progress',
            'start_date' => null,
            'end_date' => null,
            'fee' => '8000',
            'remarks' => null,
            ...$overrides,
        ];
        $this->store = route('admin.clients.services.store', $this->client);
    });

    test('a part payment becomes a cash deposit dated today', function () {
        $this->actingAs($this->admin)->post($this->store, ($this->input)(['received_now' => '3000']))->assertSessionHasNoErrors();

        $saved = ClientService::where('service_id', $this->service->id)->sole();
        $payment = $saved->payments()->sole();

        expect($payment->amount)->toBe('3000.00')
            ->and($payment->method->value)->toBe('cash')
            ->and($payment->paid_at->toDateString())->toBe(today()->toDateString())
            ->and($payment->created_by)->toBe($this->admin->id)
            ->and($saved->paymentStatus())->toBe(PaymentStatus::Deposit);
    });

    test('the first payment is dated today in Bangkok, not the UTC date', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 20:00:00', 'UTC'));

        $this->actingAs($this->admin)->post($this->store, ($this->input)(['received_now' => '3000']))->assertSessionHasNoErrors();

        expect(Payment::sole()->paid_at->toDateString())->toBe('2026-11-07');
    });

    test('paying the whole fee is fully paid, not a deposit', function () {
        $this->actingAs($this->admin)->post($this->store, ($this->input)(['received_now' => '8000']))->assertSessionHasNoErrors();

        expect(ClientService::where('service_id', $this->service->id)->sole()->paymentStatus())->toBe(PaymentStatus::FullyPaid);
    });

    test('no payment or zero creates no ledger row and stays unpaid', function (mixed $received) {
        $this->actingAs($this->admin)->post($this->store, ($this->input)(['received_now' => $received]))->assertSessionHasNoErrors();

        expect(Payment::count())->toBe(0)
            ->and(ClientService::where('service_id', $this->service->id)->sole()->paymentStatus())->toBe(PaymentStatus::Unpaid);
    })->with([null, '0', '']);

    test('more than the fee or a negative amount is rejected and nothing is saved', function (string $received) {
        $this->actingAs($this->admin)->post($this->store, ($this->input)(['received_now' => $received]))->assertSessionHasErrors('received_now');

        expect(ClientService::count())->toBe(1)
            ->and(Payment::count())->toBe(0);
    })->with(['8000.01', '-1', 'abc']);
});

describe('client page', function () {
    test('adds up the loaded payments once and again only when they are reloaded', function () {
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 1000]);
        $record = ClientService::with('payments')->find($this->record->id);

        DB::enableQueryLog();
        expect($record->received())->toBe('1000.00')
            ->and($record->remaining())->toBe('4000.00')
            ->and($record->paymentStatus())->toBe(PaymentStatus::Deposit)
            ->and(DB::getQueryLog())->toBeEmpty();

        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 500]);

        expect($record->load('payments')->received())->toBe('1500.00');
    });

    test('shows received, remaining, payment status, history and totals', function () {
        $second = ClientService::factory()->create(['client_id' => $this->client->id, 'fee' => 2000]);
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 1000, 'paid_at' => '2026-10-01']);
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 500, 'paid_at' => '2026-10-05']);
        Payment::factory()->create(['client_service_id' => $second->id, 'amount' => 2000]);
        Payment::factory()->create();

        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clientServices.data.1.received', '1500.00')
                ->where('clientServices.data.1.remaining', '3500.00')
                ->where('clientServices.data.1.payment_status', 'deposit')
                ->where('clientServices.data.1.payments.0.amount', '500.00')
                ->where('clientServices.data.1.payments.1.amount', '1000.00')
                ->where('clientServices.data.0.payment_status', 'fully_paid')
                ->where('totals.received', '3500.00')
                ->where('totals.remaining', '3500.00'));
    });
});

test('a service with payments cannot be erased from the database', function () {
    Payment::factory()->create(['client_service_id' => $this->record->id]);

    $this->record->delete();
})->throws(QueryException::class);
