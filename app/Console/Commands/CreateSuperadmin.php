<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-superadmin {--name= : Full name} {--email= : Email address}')]
#[Description('Create a superadmin account')]
class CreateSuperadmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::superadminExists()) {
            $this->error('A superadmin already exists. Only one superadmin is allowed.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->secret('Password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->role = UserRole::Superadmin;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Superadmin {$email} created.");

        return self::SUCCESS;
    }
}
