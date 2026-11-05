<?php

namespace App\Enums;

enum ClientServiceStatus: string
{
    case InProgress = 'in_progress';
    case Complete = 'complete';
    case Cancel = 'cancel';
}
