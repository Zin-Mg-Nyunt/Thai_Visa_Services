<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the command creates a verified superadmin when none exists', function () {
    $this->artisan('app:create-superadmin', ['--name' => 'Owner', '--email' => 'owner@example.com'])
        ->expectsQuestion('Password', 'a-Strong-pass-1')
        ->assertSuccessful();

    $user = User::where('email', 'owner@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Superadmin)
        ->and($user->isSuperadmin())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('a-Strong-pass-1');
});

test('the command refuses to create a second superadmin', function () {
    User::factory()->create();

    $this->artisan('app:create-superadmin', ['--name' => 'Second', '--email' => 'second@example.com'])
        ->expectsOutputToContain('Only one superadmin is allowed')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

test('the command refuses a duplicate email or a short password', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->artisan('app:create-superadmin', ['--name' => 'Owner', '--email' => 'owner@example.com'])
        ->expectsQuestion('Password', 'a-Strong-pass-1')
        ->assertFailed();

    $this->artisan('app:create-superadmin', ['--name' => 'Owner', '--email' => 'new@example.com'])
        ->expectsQuestion('Password', 'short')
        ->assertFailed();

    expect(User::count())->toBe(1);
});
