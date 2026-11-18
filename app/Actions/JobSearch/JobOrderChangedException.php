<?php

namespace App\Actions\JobSearch;

use DomainException;

/**
 * The job order or its open applications are not what the admin's screen was based on. The whole close is rolled back and
 * nothing stale is applied: the admin looks at the current state and submits again. It is not a lock error, so it is not retried.
 */
class JobOrderChangedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('The data changed while you were working. Please review the current state and try again.');
    }
}
