<?php

namespace App\Actions\Client;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;

/**
 * Limits wrong PIN guesses on a client's tracking link. The count belongs to the client,
 * so it makes no difference which address or browser the guesses come from.
 *
 * Failures are counted until the lock starts. The lock begins at the moment the limit is
 * reached, however long the earlier failures were spread out, and then lasts the full lock time.
 */
class TrackingPinGuard
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    private const FAILURE_COUNT_SECONDS = 86400;

    private string $scope;

    public function __construct(Client $client)
    {
        $this->scope = "track-pin:{$client->id}";
    }

    /**
     * Seconds left on the lock, or 0 when the link is not locked.
     */
    public function lockedForSeconds(): int
    {
        $lockedUntil = (int) Cache::get("{$this->scope}:lock", 0);

        return max(0, $lockedUntil - now()->getTimestamp());
    }

    /**
     * Wrong PINs that can still be typed before the link locks.
     */
    public function attemptsRemaining(): int
    {
        return max(0, self::MAX_ATTEMPTS - (int) Cache::get("{$this->scope}:fails", 0));
    }

    /**
     * Count a wrong PIN. Returns true when this was the one that started the lock.
     */
    public function recordFailure(): bool
    {
        Cache::add("{$this->scope}:fails", 0, self::FAILURE_COUNT_SECONDS);

        if (Cache::increment("{$this->scope}:fails") < self::MAX_ATTEMPTS) {
            return false;
        }

        Cache::put("{$this->scope}:lock", now()->addSeconds(self::LOCK_SECONDS)->getTimestamp(), self::LOCK_SECONDS);
        Cache::forget("{$this->scope}:fails");

        return true;
    }

    /**
     * A right PIN clears the count of wrong ones.
     */
    public function recordSuccess(): void
    {
        Cache::forget("{$this->scope}:fails");
    }

    /**
     * Lift the lock and start counting again from zero. Used when an admin unlocks the client.
     */
    public function reset(): void
    {
        Cache::forget("{$this->scope}:fails");
        Cache::forget("{$this->scope}:lock");
    }
}
