<?php

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function clientWithPhone(string $phone = '+66812345678'): Client
{
    return Client::factory()->create(['phone' => $phone]);
}

/**
 * Submit a PIN as a visitor with the given IP address.
 */
function submitPin(Client $client, string $pin, string $ip = '10.0.0.1'): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('track.verify', $client->unique_token), ['pin' => $pin]);
}

function failPin(Client $client, string $ip = '10.0.0.1'): TestResponse
{
    return submitPin($client, '0000', $ip);
}

function lockedMessage(int $minutes = 15): string
{
    return __('auth.pin_throttle', ['minutes' => $minutes]);
}

describe('show', function () {
    test('shows the PIN screen without any client details', function () {
        $client = clientWithPhone();

        $this->get(route('track.show', $client->unique_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Track')
                ->where('token', $client->unique_token)
                ->where('lockedMinutes', 0)
                ->missing('client')
                ->missing('phone'));
    });

    test('an unknown token is not found', function () {
        $this->get(route('track.show', 'not-a-real-token'))->assertNotFound();
    });

    test('a deleted client is not found', function () {
        $client = clientWithPhone();
        $client->delete();

        $this->get(route('track.show', $client->unique_token))->assertNotFound();
        submitPin($client, '5678')->assertNotFound();
    });

    test('the admin tracking link opens the PIN screen', function () {
        $client = clientWithPhone();

        expect($client->trackingUrl())->toBe(url("/track/{$client->unique_token}"));
        $this->get($client->trackingUrl())->assertOk();
    });
});

describe('verify', function () {
    test('the last 4 digits of the phone number open the client session', function () {
        $client = clientWithPhone('+66812345678');

        submitPin($client, '5678')
            ->assertRedirect(route('client.profile', $client->unique_token))
            ->assertSessionHasNoErrors();

        expect(session('client_ids'))->toBe([$client->id]);
    });

    test('a wrong PIN is refused and opens no session', function () {
        $client = clientWithPhone('+66812345678');

        failPin($client)->assertSessionHasErrors('pin');

        expect(session()->has('client_ids'))->toBeFalse();
    });

    test('the PIN must be 4 digits', function (?string $pin) {
        $client = clientWithPhone();

        $this->post(route('track.verify', $client->unique_token), array_filter(['pin' => $pin]))
            ->assertSessionHasErrors('pin');

        expect(session()->has('client_ids'))->toBeFalse();
    })->with([
        'missing' => null,
        'too short' => '567',
        'too long' => '56789',
        'letters' => 'abcd',
    ]);

    test('a PIN belongs only to its own client', function () {
        $client = clientWithPhone('+66812345678');
        clientWithPhone('+66899991111');

        submitPin($client, '1111')->assertSessionHasErrors('pin');
    });
});

describe('remaining attempts', function () {
    test('every wrong PIN says how many attempts are left', function () {
        $client = clientWithPhone();

        foreach ([4, 3, 2, 1] as $remaining) {
            failPin($client)->assertSessionHasErrors(['pin' => trans_choice('auth.pin_incorrect', $remaining)]);
        }
    });

    test('the fifth wrong PIN shows the lock message instead of a count', function () {
        $client = clientWithPhone();

        foreach (range(1, 4) as $attempt) {
            failPin($client);
        }

        failPin($client)->assertSessionHasErrors(['pin' => lockedMessage(15)]);
    });

    test('the page reports the lock so the form stays disabled after a reload', function () {
        $client = clientWithPhone();

        foreach (range(1, 5) as $attempt) {
            failPin($client);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get(route('track.show', $client->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('lockedMinutes', 15));

        $this->travel(5)->minutes();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get(route('track.show', $client->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('lockedMinutes', 10));

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get(route('track.show', $client->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('lockedMinutes', 10));
    });

    test('a right PIN clears the count of wrong ones', function () {
        $client = clientWithPhone('+66812345678');

        foreach (range(1, 4) as $attempt) {
            failPin($client);
        }

        submitPin($client, '5678')->assertRedirect(route('client.profile', $client->unique_token));

        failPin($client)->assertSessionHasErrors(['pin' => trans_choice('auth.pin_incorrect', 4)]);
    });
});

describe('account lock', function () {
    test('five wrong PINs lock the link, even for the right PIN', function () {
        $client = clientWithPhone('+66812345678');

        foreach (range(1, 5) as $attempt) {
            failPin($client);
        }

        submitPin($client, '5678')->assertSessionHasErrors(['pin' => lockedMessage(15)]);

        expect(session()->has('client_ids'))->toBeFalse();
    });

    test('the lock lasts 15 minutes from the fifth wrong PIN, not from the first', function () {
        $client = clientWithPhone('+66812345678');

        foreach (range(1, 5) as $attempt) {
            failPin($client);
            $this->travel(14)->minutes();
        }

        // The first wrong PIN was 70 minutes ago; the fifth was 14 minutes ago.
        submitPin($client, '5678')->assertSessionHasErrors(['pin' => lockedMessage(1)]);

        $this->travel(2)->minutes();

        submitPin($client, '5678')->assertRedirect(route('client.profile', $client->unique_token));
    });

    test('attempts spread over hours still lock at the fifth and then hold for 15 minutes', function () {
        $client = clientWithPhone('+66812345678');

        foreach (range(1, 4) as $attempt) {
            failPin($client);
            $this->travel(3)->hours();
        }

        failPin($client)->assertSessionHasErrors(['pin' => lockedMessage(15)]);

        $this->travel(14)->minutes();
        submitPin($client, '5678')->assertSessionHasErrors('pin');

        $this->travel(2)->minutes();
        submitPin($client, '5678')->assertRedirect(route('client.profile', $client->unique_token));
    });

    test('wrong PINs while locked do not extend the lock', function () {
        $client = clientWithPhone();

        foreach (range(1, 5) as $attempt) {
            failPin($client);
        }

        $this->travel(10)->minutes();
        failPin($client)->assertSessionHasErrors(['pin' => lockedMessage(5)]);

        $this->travel(6)->minutes();
        failPin($client)->assertSessionHasErrors(['pin' => trans_choice('auth.pin_incorrect', 4)]);
    });

    test('after the lock ends the client link gets five fresh attempts', function () {
        $client = clientWithPhone();

        foreach (range(1, 5) as $attempt) {
            failPin($client);
        }

        $this->travel(16)->minutes();

        foreach ([4, 3, 2, 1] as $remaining) {
            failPin($client)->assertSessionHasErrors(['pin' => trans_choice('auth.pin_incorrect', $remaining)]);
        }
    });

    test('the fifth wrong PIN locks the link whichever addresses the wrong tries came from', function () {
        $client = clientWithPhone('+66812345678');

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4'] as $ip) {
            failPin($client, $ip);
        }

        failPin($client, '10.0.0.5')->assertSessionHasErrors(['pin' => lockedMessage(15)]);

        // Every other address, with the right PIN, is refused too.
        submitPin($client, '5678', '10.0.9.9')->assertSessionHasErrors(['pin' => lockedMessage(15)]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])
            ->get(route('track.show', $client->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('lockedMinutes', 15));

        expect(session()->has('client_ids'))->toBeFalse();
    });

    test('the remaining attempts are shared by all addresses', function () {
        $client = clientWithPhone();

        failPin($client, '10.0.0.1');
        failPin($client, '10.0.0.2');

        failPin($client, '10.0.0.3')->assertSessionHasErrors(['pin' => trans_choice('auth.pin_incorrect', 2)]);
    });

    test('the lock from several addresses lasts 15 minutes from the fifth wrong PIN', function () {
        $client = clientWithPhone('+66812345678');

        foreach (range(1, 5) as $address) {
            failPin($client, "10.0.1.{$address}");
            $this->travel(1)->minutes();
        }

        // The first wrong PIN was 5 minutes ago, yet the lock began only a minute ago, so 14 minutes remain.
        submitPin($client, '5678', '10.0.2.1')->assertSessionHasErrors(['pin' => lockedMessage(14)]);

        $this->travel(15)->minutes();

        submitPin($client, '5678', '10.0.2.1')->assertRedirect(route('client.profile', $client->unique_token));
    });

    test('another client is not affected', function () {
        $client = clientWithPhone('+66812345678');
        $other = clientWithPhone('+66899991111');

        foreach (range(1, 5) as $attempt) {
            failPin($client, '10.0.0.1');
        }

        submitPin($other, '1111', '10.0.0.1')->assertRedirect(route('client.profile', $other->unique_token));
        failPin($other, '10.0.0.2')->assertSessionHasErrors(['pin' => trans_choice('auth.pin_incorrect', 4)]);
    });
});

describe('audit log', function () {
    test('every wrong PIN is logged for its own client without the PIN itself', function () {
        $client = clientWithPhone();
        $other = clientWithPhone('+66899991111');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('User-Agent', 'TestBrowser/1.0')
            ->post(route('track.verify', $client->unique_token), ['pin' => '1234']);
        failPin($other, '10.0.0.2');

        expect($client->pinAttempts)->toHaveCount(1)
            ->and($client->pinAttempts->first()->ip_address)->toBe('10.0.0.1')
            ->and($client->pinAttempts->first()->user_agent)->toBe('TestBrowser/1.0')
            ->and($client->pinAttempts->first()->caused_lock)->toBeFalse()
            ->and($other->pinAttempts)->toHaveCount(1)
            ->and($other->pinAttempts->first()->ip_address)->toBe('10.0.0.2');

        expect(json_encode(DB::table('client_pin_attempts')->get()))->not->toContain('1234');
    });

    test('only the wrong PIN that starts the lock is flagged', function () {
        $client = clientWithPhone();

        foreach (range(1, 5) as $attempt) {
            failPin($client);
        }

        expect($client->pinAttempts->pluck('caused_lock')->all())->toBe([true, false, false, false, false]);
    });

    test('tries refused while locked, a right PIN and a bad format are not logged', function () {
        $client = clientWithPhone('+66812345678');

        submitPin($client, '5678');
        submitPin($client, 'abc');
        foreach (range(1, 5) as $attempt) {
            failPin($client);
        }
        failPin($client);
        submitPin($client, '5678');

        expect($client->pinAttempts()->count())->toBe(5);
    });
});
