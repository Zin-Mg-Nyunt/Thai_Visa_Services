<?php

namespace App\Models;

use App\Enums\ClientServiceStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A file the admin uploaded for one of a client's services, kept on the private disk. A client sees it as
 * Downloadable or Locked; see isUnlocked().
 *
 * @property int $id
 * @property int $client_service_id
 * @property string $title
 * @property DocumentType $type
 * @property string $storage_key
 * @property string $mime
 * @property int $size
 * @property bool $client_can_download
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'client_service_id',
    'title',
    'type',
    'storage_key',
    'mime',
    'size',
    'client_can_download',
    'uploaded_by',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<DocumentAccessLog, $this>
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }

    /**
     * Whether the client may download it. The admin's release always unlocks it, whatever the payment status. Otherwise it
     * unlocks by itself once the service is Fully Paid; a cancelled service never unlocks by payment, because its money is
     * settled by the refund.
     */
    public function isUnlocked(): bool
    {
        if ($this->client_can_download) {
            return true;
        }

        $clientService = $this->clientService;

        return $clientService->status !== ClientServiceStatus::Cancel
            && $clientService->paymentStatus() === PaymentStatus::FullyPaid;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'size' => 'integer',
            'client_can_download' => 'boolean',
        ];
    }
}
