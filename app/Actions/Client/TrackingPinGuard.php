<?php

namespace App\Actions\Client;

use App\Models\Client;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    /**
     * How long a PIN check waits for another check on the same client to finish.
     */
    public const CHECK_WAIT_SECONDS = 3;

    /**
     * The longest one PIN check may hold the client's lock, in case a request dies while holding it.
     */
    private const CHECK_LOCK_SECONDS = 10;

    private string $scope;

    public function __construct(Client $client)
    {
        $this->scope = "track-pin:{$client->id}";
    }

    /**
     * Run a PIN check while no other check runs for this client. Without it, guesses sent at the same
     * moment would all pass the lock check before any of them was counted, getting past the try limit.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $check
     * @return TResult
     *
     * @throws LockTimeoutException when another check still holds the client after the wait
     */
    public function oneCheckAtATime(Closure $check): mixed
    {
        return Cache::lock("{$this->scope}:check", self::CHECK_LOCK_SECONDS)->block(self::CHECK_WAIT_SECONDS, $check);
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
