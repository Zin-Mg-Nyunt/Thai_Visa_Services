<?php

namespace App\Models;

use Database\Factories\ClientPinAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One wrong PIN typed on a client's tracking link. The PIN itself is never stored.
 *
 * @property int $id
 * @property int $client_id
 * @property string $ip_address
 * @property string|null $user_agent
 * @property bool $caused_lock
 * @property Carbon $created_at
 */
#[Fillable(['ip_address', 'user_agent', 'caused_lock'])]
class ClientPinAttempt extends Model
{
    /** @use HasFactory<ClientPinAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'caused_lock' => 'boolean',
        ];
    }
}
