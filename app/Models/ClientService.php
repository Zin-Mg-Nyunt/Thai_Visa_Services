<?php

namespace App\Models;

use App\Enums\ClientServiceStatus;
use App\Enums\PaymentStatus;
use Database\Factories\ClientServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One service taken by one client: its status, fee and refund. service_id is always the main service;
 * sub_service_id is the optional sub-service of that main service (empty means "main service only").
 * Payments, profit and reports belong to this record and roll up to the main service.
 *
 * @property int $id
 * @property int $client_id
 * @property int $service_id
 * @property int|null $sub_service_id
 * @property ClientServiceStatus $status
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property numeric-string $fee
 * @property numeric-string $refund_amount
 * @property Carbon|null $cancelled_at
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'client_id',
    'service_id',
    'sub_service_id',
    'status',
    'start_date',
    'end_date',
    'fee',
    'refund_amount',
    'cancelled_at',
    'remarks',
])]
class ClientService extends Model
{
    /** @use HasFactory<ClientServiceFactory> */
    use HasFactory;

    /**
     * The loaded payments that $receivedTotal was added up from, so the sum is worked out once per list.
     *
     * @var Collection<int, Payment>|null
     */
    private ?Collection $receivedFrom = null;

    /** @var numeric-string */
    private string $receivedTotal = '0.00';

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * The main service. Soft-deleted services still show on existing records.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function subService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'sub_service_id')->withTrashed();
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Money received so far, a decimal string. Exact: amounts are added as decimal strings, never floats.
     * Uses the loaded payments when there are any, so a list of services does not query once per row, and adds
     * them up only once: remaining(), paymentStatus() and the page totals all reuse the sum.
     *
     * @return numeric-string
     */
    public function received(): string
    {
        if (! $this->relationLoaded('payments')) {
            return $this->sumOf($this->payments()->get());
        }

        if ($this->receivedFrom !== $this->payments) {
            $this->receivedFrom = $this->payments;
            $this->receivedTotal = $this->sumOf($this->payments);
        }

        return $this->receivedTotal;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return numeric-string
     */
    private function sumOf(Collection $payments): string
    {
        return $payments->reduce(fn (string $total, Payment $payment) => bcadd($total, $payment->amount, 2), '0.00');
    }

    /**
     * The balance still to pay, a decimal string. It never goes below zero.
     *
     * @return numeric-string
     */
    public function remaining(): string
    {
        $remaining = bcsub($this->fee, $this->received(), 2);

        return bccomp($remaining, '0', 2) === 1 ? $remaining : '0.00';
    }

    /**
     * Unpaid when nothing is received, Deposit while part of the fee is received, Fully Paid once all of it is.
     */
    public function paymentStatus(): PaymentStatus
    {
        $received = $this->received();

        return match (true) {
            bccomp($received, '0', 2) <= 0 => PaymentStatus::Unpaid,
            bccomp($received, $this->fee, 2) >= 0 => PaymentStatus::FullyPaid,
            default => PaymentStatus::Deposit,
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClientServiceStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'fee' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }
}
