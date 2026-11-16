<?php

namespace App\Support\Concurrency;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

/**
 * Thrown when a retried workflow ran out of attempts because of deadlocks or lock-wait timeouts. The admin only ever sees
 * the friendly message; the original database error (with its SQL) is kept as the previous exception and goes to the log.
 */
class TryAgainException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(__('The system is busy. Please try again.'), 0, $previous);
    }

    /**
     * The original error is already reported by RetriedTransaction, so it is not reported a second time.
     */
    public function report(): bool
    {
        return false;
    }

    /**
     * A toast on the page the admin came from, or a short JSON message for a JSON request.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 503);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back();
    }
}
