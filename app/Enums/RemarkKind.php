<?php

namespace App\Enums;

/**
 * A remark on a client's service: a note an admin wrote, a refund note the app wrote when a fee change moved
 * the refund, or a cancel note the app wrote when the service was cancelled. The app's notes keep their numbers
 * in details, so the page shows them in the selected language.
 */
enum RemarkKind: string
{
    case Note = 'note';
    case Refund = 'refund';
    case Cancel = 'cancel';
}
