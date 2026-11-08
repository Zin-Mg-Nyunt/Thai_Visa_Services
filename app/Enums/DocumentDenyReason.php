<?php

namespace App\Enums;

/**
 * Why an attempt to open a document was denied. A document of another client, or one the client may not know about, is
 * answered with 404 so its existence is not revealed, but the attempt is still logged.
 */
enum DocumentDenyReason: string
{
    /** The client's own document, but not released and the service is not fully paid. Answered with 403. */
    case Locked = 'locked';

    /** A document that belongs to another client. Answered with 404. */
    case NotOwner = 'not_owner';

    /**
     * The client's own document that is not offered to them: a cancelled service, Job Search, a service without
     * documents, or a file that is missing from the disk. Answered with 404.
     */
    case NotAvailable = 'not_available';
}
