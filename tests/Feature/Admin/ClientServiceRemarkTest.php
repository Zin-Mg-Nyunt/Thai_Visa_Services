<?php

use App\Enums\ClientServiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RemarkKind;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Admin One']);
    $this->client = Client::factory()->create();
    $this->service = Service::factory()->create(['default_fee' => 8000]);
    $this->record = ClientService::factory()->forService($this->service)->create(['client_id' => $this->client->id, 'fee' => 8000]);
    Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => 5000]);

    $this->updateUrl = route('admin.clients.services.update', [$this->client, $this->record]);
    $this->input = fn (array $overrides = []) => [
        'sub_service_id' => null,
        'status' => 'in_progress',
        'start_date' => null,
        'end_date' => null,
        'fee' => '8000',
        'remark' => null,
        ...$overrides,
    ];
});

describe('a fee lowered below the money received', function () {
    test('records the extra as a refund with an automatic refund remark and a warning', function () {
        $this->actingAs($this->admin)
            ->put($this->updateUrl, ($this->input)(['fee' => '4000']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.clients.show', $this->client))
            ->assertInertiaFlash('toast.type', 'warning');

        $this->record->refresh();
        $remark = $this->record->remarks()->sole();

        expect($this->record->fee)->toBe('4000.00')
            ->and($this->record->refund_amount)->toBe('1000.00')
            ->and($this->record->kept())->toBe('4000.00')
            ->and($this->record->remaining())->toBe('0.00')
            ->and($this->record->paymentStatus())->toBe(PaymentStatus::FullyPaid)
            ->and($remark->kind)->toBe(RemarkKind::Refund)
            ->and($remark->details)->toBe(['from_fee' => '8000.00', 'to_fee' => '4000.00', 'received' => '5000.00', 'refund' => '1000.00'])
            ->and($remark->created_by)->toBe($this->admin->id);
    });

    test('works the refund out again when the fee is raised, with another remark', function () {
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['fee' => '4000']));
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['fee' => '3000']));
        expect($this->record->refresh()->refund_amount)->toBe('2000.00');

        $this->actingAs($this->admin)
            ->put($this->updateUrl, ($this->input)(['fee' => '6000']))
            ->assertInertiaFlash('toast.type', 'success');

        $this->record->refresh();

        expect($this->record->refund_amount)->toBe('0.00')
            ->and($this->record->remaining())->toBe('1000.00')
            ->and($this->record->remarks()->where('kind', RemarkKind::Refund)->pluck('details')->pluck('refund')->all())
            ->toBe(['1000.00', '2000.00', '0.00']);
    });

    test('adds no refund remark when the fee stays at or above the money received', function () {
        foreach (['5000', '7000', '5000.00'] as $fee) {
            $this->actingAs($this->admin)
                ->put($this->updateUrl, ($this->input)(['fee' => $fee]))
                ->assertInertiaFlash('toast.type', 'success');
        }

        expect($this->record->refresh()->refund_amount)->toBe('0.00')
            ->and($this->record->remarks()->count())->toBe(0);
    });

    test('does not change the refund of a cancelled service', function () {
        $this->record->update(['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now(), 'refund_amount' => 500]);

        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['fee' => '1000']))->assertSessionHasNoErrors();

        $this->record->refresh();

        expect($this->record->fee)->toBe('8000.00')
            ->and($this->record->refund_amount)->toBe('500.00')
            ->and($this->record->remarks()->count())->toBe(0);
    });

    test('the edit form gets the money received to warn about the refund', function () {
        $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $this->record]))
            ->assertInertia(fn (Assert $page) => $page->where('received', '5000.00'));
    });
});

describe('remarks', function () {
    test('a remark added when the service is added becomes the first in its list', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), ($this->input)(['service_id' => $this->service->id, 'remark' => 'Paid by brother']))
            ->assertSessionHasNoErrors();

        $remark = ClientServiceRemark::query()->latest('id')->first();

        expect($remark->clientService->is($this->record))->toBeFalse()
            ->and($remark->kind)->toBe(RemarkKind::Note)
            ->and($remark->body)->toBe('Paid by brother')
            ->and($remark->created_by)->toBe($this->admin->id);
    });

    test('each save adds the new remark and keeps the earlier ones', function () {
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['remark' => 'Waiting for photos']));
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['remark' => 'Photos received']));
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['remark' => '   ']));

        expect($this->record->remarks()->orderBy('id')->pluck('body')->all())->toBe(['Waiting for photos', 'Photos received']);
    });

    test('the client page lists the remarks newest first, with who wrote them', function () {
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['remark' => 'Waiting for photos']));
        $this->actingAs($this->admin)->put($this->updateUrl, ($this->input)(['fee' => '4000']));

        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clientServices.data.0.refund_amount', '1000.00')
                ->where('clientServices.data.0.received', '5000.00')
                ->where('clientServices.data.0.kept', '4000.00')
                ->where('totals.received', '4000.00')
                ->has('clientServices.data.0.remarks', 2)
                ->where('clientServices.data.0.remarks.0.kind', 'refund')
                ->where('clientServices.data.0.remarks.0.details.refund', '1000.00')
                ->where('clientServices.data.0.remarks.1.kind', 'note')
                ->where('clientServices.data.0.remarks.1.body', 'Waiting for photos')
                ->where('clientServices.data.0.remarks.1.author', 'Admin One'));
    });
});
