<?php

use App\Enums\ClientServiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RemarkKind;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->record = ClientService::factory()->create(['client_id' => $this->client->id, 'fee' => 8000]);
    Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 3000]);
    $this->cancelUrl = route('admin.clients.services.cancel', [$this->client, $this->record]);
});

describe('cancelling', function () {
    test('guests are redirected and non-superadmins are refused', function () {
        $this->post($this->cancelUrl)->assertRedirect(route('login'));

        DB::table('users')->where('id', $this->admin->id)->update(['role' => 'staff']);
        $this->actingAs($this->admin->fresh())->post($this->cancelUrl)->assertForbidden();

        expect($this->record->refresh()->status)->toBe(ClientServiceStatus::InProgress);
    });

    test('sets the status, the cancel time and the refund, and keeps the record and its payments', function () {
        $this->actingAs($this->admin)
            ->post($this->cancelUrl, ['cancel_refund_amount' => '1000'])
            ->assertRedirect(route('admin.clients.show', $this->client));

        $this->record->refresh();

        expect($this->record->status)->toBe(ClientServiceStatus::Cancel)
            ->and($this->record->cancelled_at)->not->toBeNull()
            ->and($this->record->cancel_refund_amount)->toBe('1000.00')
            ->and($this->record->received())->toBe('3000.00')
            ->and($this->record->kept())->toBe('2000.00')
            ->and(Payment::count())->toBe(1)
            ->and(ClientService::count())->toBe(1);
    });

    test('a deposit-only service has no remaining balance after cancel', function () {
        expect($this->record->remaining())->toBe('5000.00');

        $this->actingAs($this->admin)->post($this->cancelUrl)->assertSessionHasNoErrors();

        expect($this->record->refresh()->remaining())->toBe('0.00');
    });

    test('an empty refund means no refund', function (mixed $refund) {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => $refund])->assertSessionHasNoErrors();

        expect($this->record->refresh()->cancel_refund_amount)->toBe('0.00')
            ->and($this->record->kept())->toBe('3000.00');
    })->with([null, '', '0']);

    test('the refund can be the whole amount received', function () {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => '3000'])->assertSessionHasNoErrors();

        expect($this->record->refresh()->kept())->toBe('0.00');
    });

    test('rejects a cancel refund that is invalid or more than the money kept', function (string $refund) {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => $refund])->assertSessionHasErrors('cancel_refund_amount');

        expect($this->record->refresh()->status)->toBe(ClientServiceStatus::InProgress)
            ->and($this->record->cancelled_at)->toBeNull();
    })->with(['3000.01', '-1', 'abc', '1.234']);

    test('cannot cancel with a refund when nothing was received', function () {
        $unpaid = ClientService::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.cancel', [$this->client, $unpaid]), ['cancel_refund_amount' => '1'])
            ->assertSessionHasErrors('cancel_refund_amount');
    });

    test('cancel is final: a cancelled or completed service cannot be cancelled', function (string $state) {
        $record = ClientService::factory()->{$state}()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.cancel', [$this->client, $record]))
            ->assertSessionHasErrors('status');
    })->with(['cancelled', 'complete']);

    test('is not reachable through another client', function () {
        $other = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.cancel', [$other, $this->record]))
            ->assertNotFound();
    });
});

describe('cancelling after a fee change refund', function () {
    beforeEach(function () {
        // Fee 8,000 and 5,000 received; the fee is then lowered to 3,000, so the refund is 2,000 and 3,000 is kept.
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 2000]);

        $this->actingAs($this->admin)->put(route('admin.clients.services.update', [$this->client, $this->record]), [
            'sub_service_id' => null, 'status' => 'in_progress', 'start_date' => null, 'end_date' => null, 'fee' => '3000',
        ])->assertSessionHasNoErrors();

        expect($this->record->refresh()->refund_amount)->toBe('2000.00')
            ->and($this->record->kept())->toBe('3000.00');
    });

    test('keeps the fee change refund apart and takes both refunds off what is kept', function () {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => '1000'])->assertSessionHasNoErrors();

        $this->record->refresh();

        expect($this->record->refund_amount)->toBe('2000.00')
            ->and($this->record->cancel_refund_amount)->toBe('1000.00')
            ->and($this->record->received())->toBe('5000.00')
            ->and($this->record->kept())->toBe('2000.00');
    });

    test('the cancel refund cannot be more than the money kept, so kept never goes below zero', function () {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => '3000.01'])->assertSessionHasErrors('cancel_refund_amount');
        expect($this->record->refresh()->status)->toBe(ClientServiceStatus::InProgress);

        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => '3000'])->assertSessionHasNoErrors();
        expect($this->record->refresh()->kept())->toBe('0.00');
    });

    test('adds an automatic cancel remark with the numbers', function () {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => '1000']);

        $remark = $this->record->remarks()->latest('id')->first();

        expect($remark->kind)->toBe(RemarkKind::Cancel)
            ->and($remark->details)->toBe(['received' => '5000.00', 'refund' => '2000.00', 'cancel_refund' => '1000.00', 'kept' => '2000.00'])
            ->and($remark->created_by)->toBe($this->admin->id);
    });
});

describe('after cancel', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->post($this->cancelUrl, ['cancel_refund_amount' => '500']);
        $this->record->refresh();
    });

    test('no new payments can be added', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.payments.store', [$this->client, $this->record]), [
                'amount' => '100', 'paid_at' => today()->toDateString(), 'method' => 'cash',
            ])
            ->assertForbidden();

        expect(Payment::count())->toBe(1);
    });

    test('only a remark can still be added', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), [
                'status' => 'in_progress',
                'sub_service_id' => null,
                'fee' => '1',
                'start_date' => '2020-01-01',
                'end_date' => null,
                'remark' => 'Client moved abroad',
            ])
            ->assertSessionHasNoErrors();

        $this->record->refresh();

        expect($this->record->remarks()->where('kind', RemarkKind::Note)->sole()->body)->toBe('Client moved abroad')
            ->and($this->record->status)->toBe(ClientServiceStatus::Cancel)
            ->and($this->record->fee)->toBe('8000.00')
            ->and($this->record->start_date->toDateString())->not->toBe('2020-01-01')
            ->and($this->record->cancel_refund_amount)->toBe('500.00');
    });

    test('stays visible on the client page with its refund and kept amount', function () {
        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clientServices.data.0.status', 'cancel')
                ->where('clientServices.data.0.cancel_refund_amount', '500.00')
                ->where('clientServices.data.0.kept', '2500.00')
                ->where('clientServices.data.0.remaining', '0.00')
                ->where('clientServices.data.0.received', '3000.00')
                ->where('totals.remaining', '0.00')
                ->where('totals.received', '2500.00'));
    });

    test('the payment status is not used for a cancelled service on the page', function () {
        expect($this->record->paymentStatus())->toBe(PaymentStatus::Deposit);
    });
});
