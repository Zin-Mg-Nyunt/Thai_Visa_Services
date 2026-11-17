<?php

namespace App\Actions\JobSearch;

use App\Enums\EngagementState;
use App\Models\ClientService;

/**
 * The answer of FindEngagement: the state, the record to reuse (only when Reusable) and, for an inconsistent record, what was
 * found, so it can be logged.
 */
final readonly class EngagementCheck
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public EngagementState $state,
        public ?ClientService $record = null,
        public array $context = [],
    ) {}
}
