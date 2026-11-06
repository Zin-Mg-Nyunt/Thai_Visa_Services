<?php

namespace App\Models;

use App\Enums\RemarkKind;
use Database\Factories\ClientServiceRemarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One remark on a client's service. The list is add-only, like the payments: each note keeps who wrote it and when.
 *
 * @property int $id
 * @property int $client_service_id
 * @property RemarkKind $kind
 * @property string|null $body
 * @property array{from_fee: string, to_fee: string, received: string, refund: string}|array{received: string, refund: string, cancel_refund: string, kept: string}|null $details
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'client_service_id',
    'kind',
    'body',
    'details',
    'created_by',
])]
class ClientServiceRemark extends Model
{
    /** @use HasFactory<ClientServiceRemarkFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ClientService, $this>
     */
    public function clientService(): BelongsTo
    {
        return $this->belongsTo(ClientService::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RemarkKind::class,
            'details' => 'array',
        ];
    }
}
