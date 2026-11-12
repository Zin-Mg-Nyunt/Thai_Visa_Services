<?php

namespace App\Policies;

use App\Models\JobOrder;
use App\Models\User;

class JobOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, JobOrder $jobOrder): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * A closed order is final: it can no longer be edited or closed again.
     */
    public function update(User $user, JobOrder $jobOrder): bool
    {
        return $user->isSuperadmin() && $jobOrder->isOpen();
    }

    public function close(User $user, JobOrder $jobOrder): bool
    {
        return $user->isSuperadmin() && $jobOrder->isOpen();
    }
}
