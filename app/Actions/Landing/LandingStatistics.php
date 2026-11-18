<?php

namespace App\Actions\Landing;

use App\Models\ClientService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The two public numbers of the landing page, lifetime totals worked out from the database and cached for ten minutes.
 *
 * - Clients Served: the unique Person records that have at least one service record (COUNT DISTINCT client_id of the service
 *   records). Every status counts (In Progress, Complete, Cancelled), Job Search and sub-services included. A soft-deleted Person
 *   still counts, because the count never reads the clients table. Two Person records for one human are two clients: nothing
 *   is merged by name or phone. A Person with no service is not counted.
 * - Services Provided: every service record, once, whatever its status. Cancelling or completing a service never lowers it.
 *   The catalogue of services, payments, refunds and Job Order applications are not services and are not counted.
 *
 * Only totals leave this class: no names, phone numbers, payments or other data. No counters are stored; the cache is the only
 * thing kept.
 *
 * Freshness. A visitor reads the cache and, when it is empty, works the numbers out and stores them with add(), which never
 * replaces a value that is already there. Creating a Person or a service (the Eloquent `created` event, see
 * LandingStatisticsObserver, so every workflow that creates one is covered, now and later) refreshes the cache once the OUTERMOST
 * transaction has committed, never before and never after a rollback. The refresh works the numbers out from the committed data
 * and stores them, under a short lock, so two admins finishing at once cannot leave an older result behind the newer one. When
 * several records are created in one transaction (a new Person and their service, or several services) only one refresh runs.
 *
 * A failed refresh never fails the admin's operation: the data is already committed. The error is reported, the cached value is
 * dropped so the next visitor recalculates it from the database, and if even that fails the ten-minute expiry is the last
 * fallback.
 */
#[Singleton]
class LandingStatistics
{
    public const CACHE_KEY = 'landing.statistics';

    public const CACHE_SECONDS = 600;

    public const LOCK_KEY = 'landing.statistics.refresh';

    /** Whether a refresh is already waiting for the current transaction to commit. */
    private bool $refreshPending = false;

    /**
     * @return array{clients_served: int, services_provided: int}|null null when both are zero: the page then hides the section
     */
    public function forPage(): ?array
    {
        $counts = $this->counts();

        return $counts['clients_served'] === 0 && $counts['services_provided'] === 0 ? null : $counts;
    }

    /**
     * The cached numbers, or, when there are none, the numbers worked out now. They are stored only when nothing is stored yet,
     * so a visitor that was slow can never put an older result over a fresher one written by a refresh.
     *
     * @return array{clients_served: int, services_provided: int}
     */
    public function counts(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        // Only a well-formed entry is trusted; anything else is treated as missing and worked out again.
        if (is_array($cached) && is_int($cached['clients_served'] ?? null) && is_int($cached['services_provided'] ?? null)) {
            return ['clients_served' => $cached['clients_served'], 'services_provided' => $cached['services_provided']];
        }

        $counts = $this->compute();
        Cache::add(self::CACHE_KEY, $counts, self::CACHE_SECONDS);

        return $counts;
    }

    /**
     * Refresh the cache when the current transaction has committed (at once when there is none). Asked again before that, it
     * does nothing, so one operation that creates several records refreshes once. A rolled-back transaction refreshes nothing
     * and lets the next creation ask again.
     */
    public function refreshAfterCommit(): void
    {
        if ($this->refreshPending) {
            return;
        }

        $this->refreshPending = true;

        DB::afterRollBack(function (): void {
            $this->refreshPending = false;
        });

        DB::afterCommit(function (): void {
            $this->refreshPending = false;
            $this->refresh();
        });
    }

    /**
     * Work the numbers out from the committed data and store them for ten minutes. It never throws: see the class note.
     */
    public function refresh(): void
    {
        try {
            Cache::lock(self::LOCK_KEY, 10)->block(5, function (): void {
                Cache::put(self::CACHE_KEY, $this->compute(), self::CACHE_SECONDS);
            });
        } catch (Throwable $exception) {
            report($exception);

            try {
                Cache::forget(self::CACHE_KEY);
            } catch (Throwable) {
                // Nothing more can be done here. The ten-minute expiry removes the old numbers.
            }
        }
    }

    /**
     * @return array{clients_served: int, services_provided: int}
     */
    private function compute(): array
    {
        return [
            'clients_served' => ClientService::query()->distinct()->count('client_id'),
            'services_provided' => ClientService::query()->count(),
        ];
    }
}
