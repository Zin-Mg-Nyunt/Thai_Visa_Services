<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, Service $service): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Service $service): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * The Job Search service can never be deleted: the Job Search module depends on it.
     */
    public function delete(User $user, Service $service): bool
    {
        return $user->isSuperadmin() && ! $service->isJobSearch();
    }
}
