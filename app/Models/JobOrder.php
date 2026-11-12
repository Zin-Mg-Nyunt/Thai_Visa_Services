<?php

namespace App\Models;

use App\Enums\JobOrderStatus;
use Database\Factories\JobOrderFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A company's hiring request. manpower and the male/female split are what the company asked for; the workers actually
 * added are in workers(). Money totals are never stored: they are worked out from the workers' Job Search records (the
 * amount kept, received minus refunds; not a net profit, as expenses are not part of the app).
 *
 * The status and closed_at are not mass assignable: an order is closed only by close(), which sets both together.
 *
 * @property int $id
 * @property string $company_name
 * @property int $manpower
 * @property int $male_count
 * @property int $female_count
 * @property Carbon|null $interview_date
 * @property Carbon|null $start_date
 * @property JobOrderStatus $status
 * @property Carbon|null $closed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'company_name',
    'manpower',
    'male_count',
    'female_count',
    'interview_date',
    'start_date',
    'notes',
])]
class JobOrder extends Model
{
    /** @use HasFactory<JobOrderFactory> */
    use HasFactory;

    /**
     * A new order is open, also in memory right after create(): the status is not mass assignable, so it is never sent.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    public function isOpen(): bool
    {
        return $this->status === JobOrderStatus::Open;
    }

    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * Close the order as done or cancelled, with the time it closed. The row is locked while it changes, so two closes
     * sent together cannot both succeed. A closed order is final: it cannot be closed again or reopened.
     *
     * @throws InvalidArgumentException when asked to "close" as open
     * @throws DomainException when the order is already closed
     */
    public function close(JobOrderStatus $status): void
    {
        if ($status === JobOrderStatus::Open) {
            throw new InvalidArgumentException('An order is closed as done or cancelled, never as open.');
        }

        DB::transaction(function () use ($status): void {
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($locked->isClosed()) {
                throw new DomainException('A closed job order is final.');
            }

            $locked->forceFill(['status' => $status, 'closed_at' => now()])->save();

            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @return HasMany<JobOrderWorker, $this>
     */
    public function workers(): HasMany
    {
        return $this->hasMany(JobOrderWorker::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobOrderStatus::class,
            'interview_date' => 'date',
            'start_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }
}
