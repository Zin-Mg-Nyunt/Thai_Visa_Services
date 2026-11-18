<?php

namespace App\Observers;

use App\Actions\Landing\LandingStatistics;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the landing page statistics fresh. Only the creation of a Person (Client) or of a service (ClientService) can change what
 * the two numbers show or are about, so nothing else is watched: not updates, status changes, payments, refunds or applications.
 * Observing the models, not one controller, covers every place that creates them (the Client and service forms, Add Worker).
 */
class LandingStatisticsObserver
{
    public function __construct(private LandingStatistics $statistics) {}

    public function created(Model $model): void
    {
        $this->statistics->refreshAfterCommit();
    }
}
