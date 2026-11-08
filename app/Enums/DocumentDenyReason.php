<?php

namespace App\Enums;

/**
 * Why an attempt to open a document was denied. A document of another client is answered with 404 so its existence is
 * not revealed, but it is logged as not_owner.
 */
enum DocumentDenyReason: string
{
    case Locked = 'locked';
    case NotOwner = 'not_owner';
}
