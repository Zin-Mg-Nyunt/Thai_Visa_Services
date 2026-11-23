<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('a user cannot delete their own account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])
        ->assertStatus(405);

    expect($user->fresh())->not->toBeNull();
});

test('the profile updated message follows the selected language', function (string $locale, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('X-Locale', $locale)
        ->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    app()->setLocale($locale);

    expect(__('Profile updated.'))->toBe($message);
})->with([
    'my' => ['my', 'ပရိုဖိုင်ကို ပြင်ဆင်ပြီးပါပြီ။'],
    'en' => ['en', 'Profile updated.'],
]);

test('changing the email makes it unverified and sends nothing', function () {
    Mail::fake();
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'google-old']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->toBeNull()
        // The Google link is not touched here; it is checked against the new email at the next Google sign-in.
        ->and($user->google_id)->toBe('google-old');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

test('a change of capital letters only keeps the email verified', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'Owner@Example.com'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('the profile page shows the verified state from the database and offers no resend', function () {
    $verified = User::factory()->create();

    $this->actingAs($verified)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.email_verified_at', fn ($at) => $at !== null)
            ->missing('mustVerifyEmail')
            ->missing('status'));

    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.email_verified_at', null));
});

test('an unverified user keeps access to the admin area and settings', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('admin.settings'))->assertOk();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
});
