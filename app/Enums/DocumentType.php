<?php

namespace App\Enums;

/**
 * What a document on a client's service is. More types can be added later.
 */
enum DocumentType: string
{
    case WorkPermit = 'work_permit';
    case Receipt = 'receipt';
    case Other = 'other';
}
