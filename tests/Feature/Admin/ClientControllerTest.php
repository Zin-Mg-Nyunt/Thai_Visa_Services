<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, string>
 */
function newClientInput(): array
{
    return ['name' => 'Aung Min', 'phone' => '081 234 5678', 'birthday' => '1995-03-12', 'gender' => 'male', 'remarks' => 'Optional note'];
}

test('guests are redirected to the login page', function (string $method, string $routeName) {
    $client = Client::factory()->create();

    $this->{$method}(route($routeName, in_array($routeName, ['admin.clients.show', 'admin.clients.edit', 'admin.clients.update', 'admin.clients.destroy']) ? $client : []))
        ->assertRedirect(route('login'));
})->with([
    'index' => ['get', 'admin.clients.index'],
    'create' => ['get', 'admin.clients.create'],
    'store' => ['post', 'admin.clients.store'],
    'show' => ['get', 'admin.clients.show'],
    'edit' => ['get', 'admin.clients.edit'],
    'update' => ['put', 'admin.clients.update'],
    'destroy' => ['delete', 'admin.clients.destroy'],
]);

test('users without the superadmin role are refused', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())->get(route('admin.clients.index'))->assertForbidden();
    $this->actingAs($user->fresh())->post(route('admin.clients.store'), newClientInput())->assertForbidden();

    expect(Client::count())->toBe(0);
});

describe('index', function () {
    test('lists clients newest first, ten per page', function () {
        Client::factory()->count(12)->create();
        $newest = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/Index')
                ->has('clients.data', 10)
                ->where('clients.data.0.id', $newest->id)
                ->where('clients.meta.total', 13));
    });

    test('does not expose the tracking token', function () {
        Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('clients.data.0.unique_token'));
    });

    test('searches by name', function () {
        Client::factory()->create(['name' => 'Aung Min']);
        Client::factory()->create(['name' => 'Su Su']);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.index', ['q' => 'aung']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Aung Min')
                ->where('search', 'aung'));
    });

    test('searches by phone number however it is typed', function (string $typed) {
        Client::factory()->create(['name' => 'Aung Min', 'phone' => '+66812345678']);
        Client::factory()->create(['name' => 'Su Su', 'phone' => '+66899999999']);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.index', ['q' => $typed]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Aung Min'));
    })->with([
        'local format' => '081 234',
        'international format' => '+66 81 234',
        'last digits' => '5678',
    ]);

    test('does not list soft-deleted clients', function () {
        Client::factory()->create()->delete();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.index'))
            ->assertInertia(fn (Assert $page) => $page->has('clients.data', 0));
    });
});

test('the create page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.clients.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/clients/Create'));
});

describe('store', function () {
    test('creates a client with a normalised phone number and opens it', function () {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), newClientInput());

        $client = Client::firstOrFail();

        $response->assertRedirect(route('admin.clients.show', $client));
        expect($client->name)->toBe('Aung Min')
            ->and($client->phone)->toBe('+66812345678')
            ->and($client->unique_token)->not->toBeEmpty();
    });

    test('creates a new record when the phone number belonged to a deleted client', function () {
        $deleted = Client::factory()->create(['phone' => '+66812345678']);
        $deleted->delete();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), newClientInput())
            ->assertSessionHasNoErrors();

        $created = Client::firstOrFail();

        expect($created->id)->not->toBe($deleted->id)
            ->and($created->phone)->toBe('+66812345678')
            ->and($created->unique_token)->not->toBe($deleted->unique_token)
            ->and(Client::withTrashed()->find($deleted->id)->trashed())->toBeTrue();
    });

    test('rejects a duplicate phone number typed in another format', function () {
        Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), ['phone' => '0066-81-234-5678'] + newClientInput())
            ->assertSessionHasErrors('phone');

        expect(Client::count())->toBe(1);
    });

    test('rejects missing required fields', function (string $field) {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), array_diff_key(newClientInput(), [$field => true]))
            ->assertSessionHasErrors($field);

        expect(Client::count())->toBe(0);
    })->with(['name', 'phone', 'birthday', 'gender']);
});

describe('show and edit', function () {
    test('show renders the client', function () {
        $client = Client::factory()->create(['name' => 'Aung Min']);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/Show')
                ->where('client.data.id', $client->id)
                ->where('client.data.name', 'Aung Min')
                ->missing('client.data.unique_token'));
    });

    test('edit renders the client', function () {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/Edit')
                ->where('client.data.id', $client->id));
    });

    test('a soft-deleted client is not found', function (string $routeName) {
        $client = Client::factory()->create();
        $client->delete();

        $this->actingAs(User::factory()->create())
            ->get(route($routeName, $client->id))
            ->assertNotFound();
    })->with(['admin.clients.show', 'admin.clients.edit']);
});

describe('update', function () {
    test('changes the client and opens it', function () {
        $client = Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.clients.update', $client), ['name' => 'New Name', 'gender' => 'female'] + newClientInput())
            ->assertRedirect(route('admin.clients.show', $client));

        $client->refresh();
        expect($client->name)->toBe('New Name')
            ->and($client->gender->value)->toBe('female')
            ->and($client->phone)->toBe('+66812345678');
    });

    test('keeps the tracking token', function () {
        $client = Client::factory()->create();
        $token = $client->unique_token;

        $this->actingAs(User::factory()->create())
            ->put(route('admin.clients.update', $client), ['unique_token' => 'changed'] + newClientInput());

        expect($client->fresh()->unique_token)->toBe($token);
    });

    test('rejects the phone number of another client', function () {
        Client::factory()->create(['phone' => '+66899999999']);
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('admin.clients.update', $client), ['phone' => '089 999 9999'] + newClientInput())
            ->assertSessionHasErrors('phone');

        expect($client->fresh()->phone)->not->toBe('+66899999999');
    });
});

describe('destroy', function () {
    test('soft-deletes the client after the phone number is typed and returns to the list', function () {
        $client = Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => '+66812345678'])
            ->assertRedirect(route('admin.clients.index'));

        expect(Client::find($client->id))->toBeNull()
            ->and(Client::withTrashed()->find($client->id)->deleted_at)->not->toBeNull();
    });

    test('accepts the phone number typed in another format', function () {
        $client = Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => '081 234 5678'])
            ->assertRedirect(route('admin.clients.index'));

        expect(Client::find($client->id))->toBeNull();
    });

    test('keeps the client when the confirmation is missing or wrong', function (?string $confirmation) {
        $client = Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.clients.destroy', $client), array_filter(['confirmation' => $confirmation]))
            ->assertSessionHasErrors('confirmation');

        expect(Client::find($client->id))->not->toBeNull();
    })->with([
        'missing' => null,
        'another phone number' => '+66899999999',
        'the client name' => 'Aung Min',
    ]);

    test('a deleted client no longer appears in the list', function () {
        $client = Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => '+66812345678']);

        $this->get(route('admin.clients.index'))
            ->assertInertia(fn (Assert $page) => $page->has('clients.data', 0));
    });

    test('is refused for a user without the superadmin role', function () {
        $client = Client::factory()->create(['phone' => '+66812345678']);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

        $this->actingAs($user->fresh())
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => '+66812345678'])
            ->assertForbidden();

        expect(Client::find($client->id))->not->toBeNull();
    });
});

describe('tracking access', function () {
    test('the detail page receives the tracking link', function () {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.clients.show', $client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trackingUrl', url("/track/{$client->unique_token}"))
                ->missing('client.data.unique_token'));
    });
});
