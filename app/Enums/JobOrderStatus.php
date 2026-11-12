<?php

namespace App\Enums;

/**
 * Done and Cancelled are the same kind of action: both close the order. A closed order takes no new worker and is final.
 */
enum JobOrderStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
