<?php

namespace App\Policies;

use App\Enums\ClientServiceStatus;
use App\Models\ClientService;
use App\Models\User;

class PaymentPolicy
{
    /**
     * A cancelled service takes no more payments; its money is settled by the cancel flow and its refund.
     */
    public function create(User $user, ClientService $clientService): bool
    {
        return $user->isSuperadmin() && $clientService->status !== ClientServiceStatus::Cancel;
    }
}
