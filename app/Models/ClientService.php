<?php

namespace App\Models;

use App\Enums\ClientServiceStatus;
use Database\Factories\ClientServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string $fee
 * @property string $refund_amount
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
