<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

function fakeGoogleUser(string $email, string $id = 'google-123', bool $verified = true): void
{
    $googleUser = (new GoogleUser)
        ->map(['id' => $id, 'name' => 'Google Name', 'email' => $email])
        ->setRaw(['verified_email' => $verified]);

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

test('a linked google account signs in even after the email changes', function () {
    enableGoogle();
    $user = User::factory()->create(['email' => 'new@example.com', 'google_id' => 'google-123']);
    fakeGoogleUser('old@example.com');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
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
            ->where('errors.email', 'ဤ Google အကောင့်ဖြင့် ဝင်ရောက်ခွင့် မရှိပါ။'));
});

test('the refusal message follows the language kept in the locale cookie', function () {
    enableGoogle();
    fakeGoogleUser('stranger@example.com');

    $this->withUnencryptedCookie('locale', 'en')
        ->followingRedirects()
        ->get(route('auth.google.callback'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('errors.email', 'This Google account is not allowed to sign in.'));
});

test('an unverified google email cannot claim an existing account', function () {
    enableGoogle();
    User::factory()->create(['email' => 'owner@example.com']);
    fakeGoogleUser('owner@example.com', verified: false);

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an account already linked to another google id is not taken over', function () {
    enableGoogle();
    User::factory()->create(['email' => 'owner@example.com', 'google_id' => 'someone-else']);
    fakeGoogleUser('owner@example.com', id: 'attacker');

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
