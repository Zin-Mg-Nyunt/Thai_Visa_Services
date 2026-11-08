<?php

namespace App\Enums;

/**
 * The result of one attempt to open a document. For a download from Spaces, "allowed" means the signed link was
 * issued; the log cannot know whether the file was fully downloaded.
 */
enum DocumentAccessOutcome: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
}
