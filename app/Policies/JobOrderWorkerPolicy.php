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
     * The status of an order's applications can change only while the order is open: a closed order is frozen. (Payments are
     * not a worker matter: they go on through the client's page. Whole-service cancellation is a separate workflow.)
     */
    public function changeStatus(User $user, JobOrder $jobOrder): bool
    {
        return $user->isSuperadmin() && $jobOrder->isOpen();
    }

    /**
     * Hired to Cancelled is the one status change allowed on a closed order. The order stays closed: it is neither reopened
     * nor edited. This is its own ability, never part of the normal status update or the close.
     */
    public function cancelHired(User $user, JobOrderWorker $worker): bool
    {
        return $user->isSuperadmin();
    }
}
