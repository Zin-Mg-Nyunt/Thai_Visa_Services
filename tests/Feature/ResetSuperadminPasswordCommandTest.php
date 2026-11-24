<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;

const RESET_COMMAND = 'app:reset-superadmin-password';

function runReset(string $email, string $password, ?string $confirmation = null): PendingCommand
{
    return test()->artisan(RESET_COMMAND)
        ->expectsQuestion('Email address of the superadmin', $email)
        ->expectsQuestion('New password', $password)
        ->expectsQuestion('Confirm new password', $confirmation ?? $password);
}

/** A refusal that happens at the email step: the password is never even asked for. */
function runResetRefusedAtEmail(string $email): PendingCommand
{
    return test()->artisan(RESET_COMMAND)
        ->expectsQuestion('Email address of the superadmin', $email);
}

test('a superadmin password is reset, and the new password works while the old one does not', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    runReset('owner@example.com', 'a-Strong-pass-1')
        ->expectsOutputToContain('The password was changed.')
        ->assertSuccessful();

    $user->refresh();
    expect(Hash::check('a-Strong-pass-1', $user->password))->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('the password is stored hashed and never printed', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $oldHash = $user->password;

    runReset('owner@example.com', 'a-Strong-pass-1')
        ->doesntExpectOutputToContain('a-Strong-pass-1')
        ->assertSuccessful();

    $stored = $user->fresh()->password;

    expect($stored)->not->toBe('a-Strong-pass-1')
        ->and($stored)->not->toBe($oldHash)
        ->and(Hash::isHashed($stored))->toBeTrue();
    $this->assertDatabaseMissing('users', ['password' => 'a-Strong-pass-1']);
});

test('the email is found whatever its capital letters or spaces', function () {
    $user = User::factory()->create(['email' => 'Owner@Example.com']);

    runReset('  OWNER@example.COM ', 'a-Strong-pass-1')->assertSuccessful();

    expect(Hash::check('a-Strong-pass-1', $user->fresh()->password))->toBeTrue();
});

test('an email that belongs to nobody is refused and nothing changes', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $before = $user->password;

    runResetRefusedAtEmail('nobody@example.com')
        ->expectsOutputToContain('No user has that email address.')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($before)
        ->and(User::count())->toBe(1);
});

test('an empty email is refused', function () {
    User::factory()->create();

    test()->artisan(RESET_COMMAND)
        ->expectsQuestion('Email address of the superadmin', '')
        ->expectsOutputToContain('No user has that email address.')
        ->assertFailed();
});

test('a user who is not a superadmin is refused and nothing changes', function () {
    $user = User::factory()->create(['email' => 'staff@example.com']);
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);
    $before = $user->fresh()->getRawOriginal('password');

    runResetRefusedAtEmail('staff@example.com')
        ->expectsOutputToContain('not a superadmin')
        ->assertFailed();

    $after = DB::table('users')->where('id', $user->id)->first();
    expect($after->password)->toBe($before)
        ->and($after->role)->toBe('staff');
});

test('a password that is not confirmed is refused and nothing changes', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $before = $user->password;

    runReset('owner@example.com', 'a-Strong-pass-1', 'a-Different-pass-2')
        ->expectsOutputToContain('The password was not changed.')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($before);
});

test('a password that is too short is refused and nothing changes', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $before = $user->password;

    runReset('owner@example.com', 'short')->assertFailed();

    expect($user->fresh()->password)->toBe($before);
});

test('in production the strong password rules apply, as on the Security page', function () {
    app()->detectEnvironment(fn () => 'production');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $before = $user->password;

    // Long enough, but no capital letter, number or symbol.
    runReset('owner@example.com', 'alllowercaseletters')->assertFailed();
    expect($user->fresh()->password)->toBe($before);

    runReset('owner@example.com', 'A-very-Strong-pass-1!')->assertSuccessful();
    expect(Hash::check('A-very-Strong-pass-1!', $user->fresh()->password))->toBeTrue();
});

test('the sign-ins of that user are ended, and nobody else is touched', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    $tokenBefore = $user->remember_token;

    foreach (['mine-1' => $user->id, 'mine-2' => $user->id, 'theirs' => $other->id] as $id => $userId) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    runReset('owner@example.com', 'a-Strong-pass-1')->assertSuccessful();

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['theirs'])
        ->and($user->fresh()->remember_token)->not->toBe($tokenBefore);
});

test('a failed reset keeps the sign-ins of that user', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    DB::table('sessions')->insert(['id' => 'mine', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    runReset('owner@example.com', 'short')->assertFailed();

    expect(DB::table('sessions')->count())->toBe(1);
});

test('the command takes no password, email or other input from the command line', function () {
    $definition = Artisan::all()[RESET_COMMAND]->getDefinition();

    expect($definition->getArguments())->toBe([])
        ->and(array_keys($definition->getOptions()))->not->toContain('password', 'email');
});

test('it creates nobody, grants no role, and sends nothing', function () {
    Mail::fake();
    Notification::fake();
    User::factory()->create(['email' => 'owner@example.com']);

    runResetRefusedAtEmail('nobody@example.com')->assertFailed();
    runReset('owner@example.com', 'a-Strong-pass-1')->assertSuccessful();

    expect(User::count())->toBe(1);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});
