<?php

namespace App\Enums;

/**
 * Who opened a document: a client signed in to the portal, or the superadmin.
 */
enum DocumentActor: string
{
    case Client = 'client';
    case Admin = 'admin';
}
