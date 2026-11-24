<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Password recovery for the superadmin. The app sends no email, so a forgotten password cannot be reset from the website;
 * it is recovered on the server, by someone who can run Artisan there.
 *
 * - Everything is typed at the prompts: the email, then the new password twice with the typing hidden. There is no option
 *   or argument that takes a password, so one can never end up in the shell history or the process list.
 * - Only an existing user with the superadmin role can be changed. Nobody is created and no role is granted.
 * - The new password must pass the same rules as the Security page (PasswordValidationRules).
 * - The password is never printed or logged. Other sign-ins of that user are ended, as the old password may have been known.
 */
#[Signature('app:reset-superadmin-password')]
#[Description('Set a new password for the superadmin (server-side recovery; nothing is emailed)')]
class ResetSuperadminPassword extends Command
{
    use PasswordValidationRules;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->ask('Email address of the superadmin')));

        $user = $email === ''
            ? null
            : User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }

        if (! $user->isSuperadmin()) {
            $this->error('That user is not a superadmin. This command resets only a superadmin password.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            [
                'password' => $this->secret('New password'),
                'password_confirmation' => $this->secret('Confirm new password'),
            ],
            ['password' => $this->passwordRules()],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            $this->error('The password was not changed.');

            return self::FAILURE;
        }

        $password = $validator->validated()['password'];

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // The old password may have been known to someone: end every sign-in this user has.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        $this->info('The password was changed.');

        return self::SUCCESS;
    }
}
