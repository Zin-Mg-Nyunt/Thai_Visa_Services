<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/** $verified: true or false is Google's email_verified claim; null leaves the claim out of the answer. */
function fakeGoogleUser(string $email, string $id = 'google-123', ?bool $verified = true): void
{
    $googleUser = (new GoogleUser)
        ->map(['id' => $id, 'name' => 'Google Name', 'email' => $email])
        ->setRaw($verified === null ? [] : ['verified_email' => $verified]);

    Socialite::fake('google', $googleUser);
}

function enableGoogle(): void
{
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);
}

test('google sign-in is hidden and unavailable until it is configured', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('googleEnabled', false));

    $this->get(route('auth.google.redirect'))->assertNotFound();
    $this->get(route('auth.google.callback'))->assertNotFound();
});

test('the login page offers google sign-in when it is configured', function () {
    enableGoogle();

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
});

test('an existing superadmin can sign in with a verified google account', function () {
    enableGoogle();
    $user = User::factory()->unverified()->create(['email' => 'owner@example.com']);
    fakeGoogleUser('owner@example.com');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->google_id)->toBe('google-123')
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

test('an existing google account with a matching verified email signs in as before', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => 'google-123']);
    fakeGoogleUser('owner@example.com');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->google_id)->toBe('google-123');
});

test('the old google account cannot sign in after the app email changed', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'new@example.com', 'google_id' => 'google-old', 'email_verified_at' => null]);
    fakeGoogleUser('old@example.com', id: 'google-old');

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
    $user->refresh();
    expect($user->google_id)->toBe('google-old')
        ->and($user->email_verified_at)->toBeNull();
});

test('a new google account with the new email relinks to the same user and verifies the email', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'new@example.com', 'google_id' => 'google-old', 'email_verified_at' => null]);
    fakeGoogleUser('new@example.com', id: 'google-new');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->google_id)->toBe('google-new')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(User::count())->toBe(1);
});

test('the email is compared without regard to capital letters or spaces', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'Owner@Example.COM', 'google_id' => null, 'email_verified_at' => null]);
    fakeGoogleUser('  oWNER@example.com ', id: 'google-9');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->google_id)->toBe('google-9');
});

test('a google email that google has not verified is refused and nothing changes', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => 'google-old', 'email_verified_at' => null]);
    fakeGoogleUser('owner@example.com', id: 'google-new', verified: false);

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
    $user->refresh();
    expect($user->google_id)->toBe('google-old')
        ->and($user->email_verified_at)->toBeNull();
});

test('a missing email_verified claim is refused and nothing changes', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => null, 'email_verified_at' => null]);
    fakeGoogleUser('owner@example.com', id: 'google-new', verified: null);

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
    $user->refresh();
    expect($user->google_id)->toBeNull()
        ->and($user->email_verified_at)->toBeNull();
});

test('a google account linked to another user is refused and neither user changes', function () {
    enableGoogle();
    $other = User::factory()->create(['email' => 'other@example.com', 'google_id' => 'google-shared']);
    $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => 'google-mine', 'email_verified_at' => null]);
    fakeGoogleUser('owner@example.com', id: 'google-shared');

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($other->fresh()->google_id)->toBe('google-shared')
        ->and($user->fresh()->google_id)->toBe('google-mine')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

test('repeating the callback keeps one consistent identity', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'new@example.com', 'google_id' => 'google-old', 'email_verified_at' => null]);

    foreach (range(1, 3) as $ignored) {
        fakeGoogleUser('new@example.com', id: 'google-new');
        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));
        auth()->logout();
    }

    expect(User::count())->toBe(1)
        ->and($user->fresh()->google_id)->toBe('google-new')
        ->and(User::where('google_id', 'google-new')->count())->toBe(1);
});

test('an unknown google account is refused and no account is created', function () {
    enableGoogle();
    User::factory()->create(['email' => 'owner@example.com']);
    fakeGoogleUser('stranger@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe(1);
});

test('a refused google sign-in shows its message on the login page', function () {
    enableGoogle();
    fakeGoogleUser('stranger@example.com');

    $this->followingRedirects()
        ->get(route('auth.google.callback'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('errors.email', __('auth.google_not_allowed', [], 'my')));
});

test('the refusal message follows the language kept in the locale cookie', function () {
    enableGoogle();
    fakeGoogleUser('stranger@example.com');

    $this->withUnencryptedCookie('locale', 'en')
        ->followingRedirects()
        ->get(route('auth.google.callback'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('errors.email', __('auth.google_not_allowed', [], 'en')));
});

test('an unverified google email cannot claim an existing account', function () {
    enableGoogle();
    User::factory()->create(['email' => 'owner@example.com']);
    fakeGoogleUser('owner@example.com', verified: false);

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('cancelling on the google screen returns to the login page with a message', function () {
    enableGoogle();

    $this->get(route('auth.google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
