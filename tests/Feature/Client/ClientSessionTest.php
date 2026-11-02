<?php

use App\Models\Client;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function signInClient(Client $client, string $pin): void
{
    test()->post(route('track.verify', $client->unique_token), ['pin' => $pin]);
}

test('a client with a PIN session sees their own page', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);

    $this->post(route('track.verify', $client->unique_token), ['pin' => '5678'])
        ->assertRedirect(route('client.profile', $client->unique_token));

    $this->get(route('client.profile', $client->unique_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/Profile')
            ->where('token', $client->unique_token)
            ->where('profile.data', [
                'name' => $client->name,
                'phone' => $client->phone,
                'birthday' => $client->birthday->toDateString(),
                'gender' => $client->gender->value,
            ]));
});

test('without a client session the client pages send the visitor to that client\'s PIN page', function () {
    $client = Client::factory()->create();

    $this->get(route('client.profile', $client->unique_token))
        ->assertRedirect(route('track.show', $client->unique_token));
    $this->post(route('client.logout', $client->unique_token))
        ->assertRedirect(route('track.show', $client->unique_token));
});

test('an unknown token is not found', function () {
    $this->get(route('client.profile', 'not-a-real-token'))->assertNotFound();
});

test('a superadmin login does not open the client pages', function () {
    $client = Client::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('client.profile', $client->unique_token))
        ->assertRedirect(route('track.show', $client->unique_token));
});

test('a client session does not open the admin pages', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    signInClient($client, '5678');

    $this->get(route('admin.clients.index'))->assertRedirect(route('login'));
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a client deleted after signing in loses access', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    signInClient($client, '5678');

    $client->delete();

    $this->get(route('client.profile', $client->unique_token))->assertNotFound();
});

test('logging out ends the client session and returns to their PIN page', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    signInClient($client, '5678');

    $this->post(route('client.logout', $client->unique_token))
        ->assertRedirect(route('track.show', $client->unique_token));

    expect(session('client_ids'))->toBe([]);
    $this->get(route('client.profile', $client->unique_token))
        ->assertRedirect(route('track.show', $client->unique_token));
});

test('logging out as a client keeps a superadmin signed in on the same browser', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    $admin = User::factory()->create();

    $this->actingAs($admin);
    signInClient($client, '5678');

    $this->post(route('client.logout', $client->unique_token));

    $this->assertAuthenticatedAs($admin);
});

test('logging out renews the CSRF token', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    signInClient($client, '5678');

    $tokenBeforeLogout = session()->token();

    $this->post(route('client.logout', $client->unique_token));

    expect(session()->token())->not->toBe($tokenBeforeLogout);
});

test('a signed-in client opening their own link goes straight to their profile', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    signInClient($client, '5678');

    $this->get(route('track.show', $client->unique_token))
        ->assertRedirect(route('client.profile', $client->unique_token));
});

test('a signed-in client opening another client\'s link still sees the PIN screen', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    $other = Client::factory()->create(['phone' => '+66899991111']);
    signInClient($client, '5678');

    $this->get(route('track.show', $other->unique_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/Track'));
});

test('a signed-in client cannot open another client\'s portal', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);
    $other = Client::factory()->create(['phone' => '+66899991111']);
    signInClient($client, '5678');

    $this->get(route('client.profile', $other->unique_token))
        ->assertRedirect(route('track.show', $other->unique_token));
});

describe('two clients on one browser', function () {
    test('both stay signed in and each sees their own page', function () {
        $first = Client::factory()->create(['phone' => '+66812345678']);
        $second = Client::factory()->create(['phone' => '+66899991111']);

        signInClient($first, '5678');
        signInClient($second, '1111');

        $this->get(route('client.profile', $first->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('profile.data.name', $first->name));
        $this->get(route('client.profile', $second->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('profile.data.name', $second->name));
    });

    test('signing out the first client returns to their own PIN page and keeps the second signed in', function () {
        $first = Client::factory()->create(['phone' => '+66812345678']);
        $second = Client::factory()->create(['phone' => '+66899991111']);

        signInClient($first, '5678');
        signInClient($second, '1111');

        $this->post(route('client.logout', $first->unique_token))
            ->assertRedirect(route('track.show', $first->unique_token));

        $this->get(route('client.profile', $first->unique_token))
            ->assertRedirect(route('track.show', $first->unique_token));
        $this->get(route('client.profile', $second->unique_token))->assertOk();
    });
});

test('the profile data never includes the tracking token, the id or the agency remarks', function () {
    $client = Client::factory()->create(['phone' => '+66812345678', 'remarks' => 'Private agency note']);
    signInClient($client, '5678');

    $response = $this->get(route('client.profile', $client->unique_token))->assertOk();

    expect(json_encode($response->viewData('page')['props']['profile']))
        ->not->toContain($client->unique_token)
        ->not->toContain('Private agency note');

    expect(json_encode($response->viewData('page')['props']))->not->toContain('Private agency note');

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('profile.data.id')
        ->missing('profile.data.unique_token')
        ->missing('profile.data.remarks'));
});

test('each client sees only their own details', function () {
    $first = Client::factory()->create(['name' => 'First Client', 'phone' => '+66812345678']);
    $second = Client::factory()->create(['name' => 'Second Client', 'phone' => '+66899991111']);

    signInClient($second, '1111');

    $response = $this->get(route('client.profile', $second->unique_token))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.data.name', 'Second Client')
            ->where('profile.data.phone', $second->phone));

    expect(json_encode($response->viewData('page')['props']))
        ->not->toContain('First Client')
        ->not->toContain($first->phone);
});
