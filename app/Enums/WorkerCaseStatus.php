<?php

namespace App\Enums;

/**
 * Where an application is in the hiring flow of one job order. Separate from the payment status of the worker's service.
 *
 * Hired, Rejected and Cancelled are final. Cancelled means the worker no longer wants this job order; it is not the same
 * as cancelling the whole Job Search service.
 */
enum WorkerCaseStatus: string
{
    case Applied = 'applied';
    case Interview = 'interview';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * The statuses an application may move to from this one. Applied and Interview can go back and forth, and either can end
     * as Hired, Rejected or Cancelled. Those three are final: nothing leads out of them, and a Rejected or Cancelled
     * application is never reactivated (a new application row is created instead).
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Applied => [self::Interview, self::Hired, self::Rejected, self::Cancelled],
            self::Interview => [self::Applied, self::Hired, self::Rejected, self::Cancelled],
            self::Hired, self::Rejected, self::Cancelled => [],
        };
    }

    public function canChangeTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Hired, Rejected and Cancelled are final under the normal status updates.
     */
    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
