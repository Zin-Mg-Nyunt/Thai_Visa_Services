<?php

namespace App\Policies;

use App\Models\ClientService;
use App\Models\User;

class ClientServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, ClientService $clientService): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, ClientService $clientService): bool
    {
        return $user->isSuperadmin();
    }
}
