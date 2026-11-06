<?php

use App\Enums\ClientServiceStatus;
use App\Enums\ServiceAvailability;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clientServiceInput(Service $service, array $overrides = []): array
{
    return [
        'service_id' => $service->id,
        'sub_service_id' => null,
        'status' => 'in_progress',
        'start_date' => '2026-10-01',
        'end_date' => null,
        'fee' => '8000.00',
        'remarks' => null,
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->workPermit = Service::factory()->create(['title' => 'Work Permit', 'default_fee' => 8000]);
    $this->passport = Service::factory()->create(['title' => 'Passport', 'default_fee' => 3500]);
    $this->bath = Service::factory()->subServiceOf($this->passport)->create(['title' => 'Bath', 'default_fee' => 3000]);
    $this->mou = Service::factory()->subServiceOf($this->passport)->create(['title' => 'MOU', 'default_fee' => null]);
});

test('guests are redirected to the login page', function () {
    $clientService = ClientService::factory()->create();

    $this->get(route('admin.clients.services.create', $this->client))->assertRedirect(route('login'));
    $this->post(route('admin.clients.services.store', $this->client), [])->assertRedirect(route('login'));
    $this->get(route('admin.clients.services.edit', [$clientService->client, $clientService]))->assertRedirect(route('login'));
    $this->put(route('admin.clients.services.update', [$clientService->client, $clientService]), [])->assertRedirect(route('login'));
});

test('users without the superadmin role are refused', function () {
    $clientService = ClientService::factory()->create();
    DB::table('users')->where('id', $this->admin->id)->update(['role' => 'staff']);
    $user = $this->admin->fresh();

    $this->actingAs($user)->get(route('admin.clients.services.create', $this->client))->assertForbidden();
    $this->actingAs($user)->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->workPermit))->assertForbidden();
    $this->actingAs($user)->put(route('admin.clients.services.update', [$clientService->client, $clientService]), [])->assertForbidden();

    expect(ClientService::count())->toBe(1);
});

describe('client page', function () {
    test('lists the services of the client with their sub-service or "main service only"', function () {
        ClientService::factory()->forService($this->passport, $this->mou)->create(['client_id' => $this->client->id]);
        ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->client->id]);
        ClientService::factory()->create();

        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/Show')
                ->has('clientServices.data', 2)
                ->where('clientServices.data.0.service.title', 'Work Permit')
                ->where('clientServices.data.0.sub_service', null)
                ->where('clientServices.data.1.sub_service.title', 'MOU')
                ->where('clientServices.data.1.fee', fn ($fee) => is_string($fee)));
    });
});

describe('add form', function () {
    test('offers the active main services with their active sub-services and fees, never Job Search', function () {
        Service::factory()->create(['title' => 'Job Search', 'key' => Service::JOB_SEARCH_KEY]);
        Service::factory()->create(['title' => 'Old', 'status' => ServiceAvailability::Inactive]);
        Service::factory()->create(['title' => 'Gone'])->delete();
        Service::factory()->subServiceOf($this->passport)->create(['title' => 'Hidden', 'status' => ServiceAvailability::Inactive]);

        $this->actingAs($this->admin)->get(route('admin.clients.services.create', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/services/Create')
                ->where('serviceOptions', fn ($options) => collect($options)->pluck('title')->all() === ['Work Permit', 'Passport'])
                ->where('serviceOptions.1.sub_services', fn ($subs) => collect($subs)->pluck('title')->all() === ['Bath', 'MOU'])
                ->where('serviceOptions.1.sub_services.0.default_fee', '3000.00')
                ->where('serviceOptions.1.sub_services.1.default_fee', null));
    });

    test('a deleted client has no add form', function () {
        $this->client->delete();

        $this->actingAs($this->admin)->get(route('admin.clients.services.create', $this->client))->assertNotFound();
    });
});

describe('adding a service', function () {
    test('works with the main service only', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->workPermit))
            ->assertRedirect(route('admin.clients.show', $this->client));

        $saved = $this->client->clientServices()->sole();

        expect($saved->service_id)->toBe($this->workPermit->id)
            ->and($saved->sub_service_id)->toBeNull()
            ->and($saved->status)->toBe(ClientServiceStatus::InProgress)
            ->and($saved->fee)->toBe('8000.00')
            ->and($saved->start_date->toDateString())->toBe('2026-10-01');
    });

    test('works with a sub-service and keeps the main service in service_id', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->passport, ['sub_service_id' => $this->bath->id, 'fee' => '3000']))
            ->assertRedirect(route('admin.clients.show', $this->client));

        $saved = $this->client->clientServices()->sole();

        expect($saved->service_id)->toBe($this->passport->id)
            ->and($saved->sub_service_id)->toBe($this->bath->id);
    });

    test('allows the same service twice for one client', function () {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->admin)
                ->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->workPermit))
                ->assertSessionHasNoErrors();
        }

        expect($this->client->clientServices()->count())->toBe(2);
    });

    test('rejects services that cannot be added', function (string $case) {
        $service = match ($case) {
            'job search' => Service::factory()->create(['key' => Service::JOB_SEARCH_KEY]),
            'inactive' => Service::factory()->create(['status' => ServiceAvailability::Inactive]),
            'deleted' => tap(Service::factory()->create())->delete(),
            'sub-service as the main service' => $this->bath,
        };

        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($service))
            ->assertSessionHasErrors('service_id');

        expect(ClientService::count())->toBe(0);
    })->with(['job search', 'inactive', 'deleted', 'sub-service as the main service']);

    test('rejects a missing service', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->workPermit, ['service_id' => null]))
            ->assertSessionHasErrors('service_id');
    });

    test('rejects a sub-service of another service, a main service, or an unavailable sub-service', function (string $case) {
        $inactive = Service::factory()->subServiceOf($this->passport)->create(['status' => ServiceAvailability::Inactive]);
        $deleted = tap(Service::factory()->subServiceOf($this->passport)->create())->delete();

        $subServiceId = match ($case) {
            'other service' => $this->bath->id,
            'main service' => $this->workPermit->id,
            'inactive' => $inactive->id,
            'deleted' => $deleted->id,
            'unknown' => 99999,
        };
        $service = $case === 'other service' ? $this->workPermit : $this->passport;

        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($service, ['sub_service_id' => $subServiceId]))
            ->assertSessionHasErrors('sub_service_id');

        expect(ClientService::count())->toBe(0);
    })->with(['other service', 'main service', 'inactive', 'deleted', 'unknown']);

    test('validates status, dates and fee', function (array $input, string $field) {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->workPermit, $input))
            ->assertSessionHasErrors($field);
    })->with([
        'cancel is not offered here' => [['status' => 'cancel'], 'status'],
        'unknown status' => [['status' => 'done'], 'status'],
        'end before start' => [['start_date' => '2026-10-05', 'end_date' => '2026-10-01'], 'end_date'],
        'bad date' => [['start_date' => 'soon'], 'start_date'],
        'missing fee' => [['fee' => null], 'fee'],
        'negative fee' => [['fee' => '-1'], 'fee'],
        'three decimals' => [['fee' => '10.123'], 'fee'],
        'too large' => [['fee' => '10000000000'], 'fee'],
        'long remarks' => [['remarks' => str_repeat('a', 2001)], 'remarks'],
    ]);

    test('accepts a zero fee, empty dates and a complete status', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.store', $this->client), clientServiceInput($this->workPermit, [
                'fee' => '0', 'start_date' => null, 'status' => 'complete', 'remarks' => 'Done early',
            ]))
            ->assertSessionHasNoErrors();

        expect($this->client->clientServices()->sole()->status)->toBe(ClientServiceStatus::Complete);
    });
});

describe('editing a service', function () {
    beforeEach(function () {
        $this->record = ClientService::factory()->forService($this->passport, $this->bath)->create([
            'client_id' => $this->client->id,
            'fee' => 3000,
        ]);
    });

    test('the form offers the active sub-services of its service and keeps the service fixed', function () {
        $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $this->record]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/services/Edit')
                ->where('clientService.data.service.title', 'Passport')
                ->where('clientService.data.sub_service.title', 'Bath')
                ->where('subServiceOptions', fn ($subs) => collect($subs)->pluck('title')->all() === ['Bath', 'MOU'])
                ->where('subServiceOptions.0.default_fee', '3000.00')
                ->where('serviceDefaultFee', '3500.00'));
    });

    test('keeps the current sub-service in the list even after it becomes inactive', function () {
        $this->bath->update(['status' => ServiceAvailability::Inactive]);

        $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $this->record]))
            ->assertInertia(fn (Assert $page) => $page->where('subServiceOptions', fn ($subs) => collect($subs)->pluck('title')->all() === ['Bath', 'MOU']));

        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->passport, ['sub_service_id' => $this->bath->id, 'fee' => '3000']))
            ->assertSessionHasNoErrors();
    });

    test('keeps the current sub-service in the list even after it is deleted', function () {
        $this->bath->delete();

        $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $this->record]))
            ->assertInertia(fn (Assert $page) => $page->where('subServiceOptions', fn ($subs) => collect($subs)->pluck('title')->all() === ['Bath', 'MOU']));

        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->passport, ['sub_service_id' => $this->bath->id, 'fee' => '3000']))
            ->assertSessionHasNoErrors();
    });

    test('does not offer or accept other deleted or inactive sub-services', function () {
        $inactive = Service::factory()->subServiceOf($this->passport)->create(['title' => 'Old', 'status' => ServiceAvailability::Inactive]);
        $deleted = tap(Service::factory()->subServiceOf($this->passport)->create(['title' => 'Gone']))->delete();

        $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$this->client, $this->record]))
            ->assertInertia(fn (Assert $page) => $page->where('subServiceOptions', fn ($subs) => collect($subs)->pluck('title')->all() === ['Bath', 'MOU']));

        foreach ([$inactive, $deleted] as $subService) {
            $this->actingAs($this->admin)
                ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->passport, ['sub_service_id' => $subService->id]))
                ->assertSessionHasErrors('sub_service_id');
        }

        expect($this->record->refresh()->sub_service_id)->toBe($this->bath->id);
    });

    test('changes the sub-service, status, dates, fee and remarks', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->passport, [
                'sub_service_id' => $this->mou->id,
                'status' => 'complete',
                'end_date' => '2026-10-28',
                'fee' => '3500.00',
                'remarks' => 'Collected',
            ]))
            ->assertRedirect(route('admin.clients.show', $this->client));

        $this->record->refresh();

        expect($this->record->sub_service_id)->toBe($this->mou->id)
            ->and($this->record->status)->toBe(ClientServiceStatus::Complete)
            ->and($this->record->end_date->toDateString())->toBe('2026-10-28')
            ->and($this->record->fee)->toBe('3500.00')
            ->and($this->record->remarks)->toBe('Collected');
    });

    test('can go back to the main service only', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->passport))
            ->assertSessionHasNoErrors();

        expect($this->record->refresh()->sub_service_id)->toBeNull();
    });

    test('never changes the service, even if one is sent', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->workPermit, ['sub_service_id' => $this->mou->id]))
            ->assertSessionHasNoErrors();

        expect($this->record->refresh()->service_id)->toBe($this->passport->id);
    });

    test('rejects a sub-service of another service', function () {
        $other = Service::factory()->subServiceOf($this->workPermit)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$this->client, $this->record]), clientServiceInput($this->passport, ['sub_service_id' => $other->id]))
            ->assertSessionHasErrors('sub_service_id');
    });

    test('is not reachable through another client', function () {
        $other = Client::factory()->create();

        $this->actingAs($this->admin)->get(route('admin.clients.services.edit', [$other, $this->record]))->assertNotFound();
        $this->actingAs($this->admin)
            ->put(route('admin.clients.services.update', [$other, $this->record]), clientServiceInput($this->passport))
            ->assertNotFound();
    });
});
