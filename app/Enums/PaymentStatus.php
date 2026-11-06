<?php

namespace App\Enums;

/**
 * Worked out from the payments of a client service; never stored.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Deposit = 'deposit';
    case FullyPaid = 'fully_paid';
}
