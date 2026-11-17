<?php

namespace App\Actions\JobSearch;

use DomainException;

/**
 * A Job Search record in a state that normal workflows cannot produce. The operation is refused and the details are logged;
 * nothing is repaired automatically.
 */
class InconsistentEngagement extends DomainException
{
    /**
     * @param  array<string, mixed>  $context  What was found, for the log.
     */
    public function __construct(public readonly array $context = [])
    {
        parent::__construct("This person's Job Search record is in an unexpected state, so nothing was changed. Please check it first.");
    }
}
