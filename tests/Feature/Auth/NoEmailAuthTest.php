<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// The app has no outgoing email service, so no workflow that needs one may be offered.

test('no password reset or email verification route exists', function () {
    foreach ([
        'password.request', 'password.email', 'password.reset', 'password.update',
        'verification.notice', 'verification.verify', 'verification.send',
    ] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} should not exist");
    }

    $this->get('/admin/forgot-password')->assertNotFound();
    $this->post('/admin/forgot-password', ['email' => 'a@example.com'])->assertNotFound();
    $this->get('/admin/reset-password/token')->assertNotFound();
    $this->actingAs(User::factory()->unverified()->create())->get('/admin/email/verify')->assertNotFound();
});

test('the login page offers no password reset link', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->missing('canResetPassword'));
});

test('signing in and out sends nothing', function () {
    Mail::fake();
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->post(route('logout'));

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('an unverified user is not sent to a verification page', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('guests and users without the superadmin role are still kept out of the admin area', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertForbidden();
});
