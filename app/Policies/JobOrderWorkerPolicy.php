<?php

namespace App\Policies;

use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\User;

class JobOrderWorkerPolicy
{
    public function view(User $user, JobOrderWorker $worker): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * A closed job order takes no new worker.
     */
    public function create(User $user, JobOrder $jobOrder): bool
    {
        return $user->isSuperadmin() && $jobOrder->isOpen();
    }

    /**
     * Workers can still be updated after the order closes: their case status and payments go on.
     */
    public function update(User $user, JobOrderWorker $worker): bool
    {
        return $user->isSuperadmin();
    }
}
