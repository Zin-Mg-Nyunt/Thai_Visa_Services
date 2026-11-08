<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A file the admin uploaded for one of a client's services, kept on the private disk. A client sees it as
 * Downloadable or Locked; see isUnlocked().
 *
 * @property int $id
 * @property int $client_service_id
 * @property string $title
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

    public const DISK = 'private';

    public const DIRECTORY = 'documents';

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

        return $this->clientService->unlocksDocuments();
    }

    /**
     * Delete the record and then its file. The record goes first, in a transaction, and the file only after the commit, so a
     * failed delete never leaves a record that points at a missing file. A file that cannot be removed is reported (the disk
     * does not throw) and never blocks the delete. The access history stays: it keeps its own copies.
     */
    public function erase(): void
    {
        $key = $this->storage_key;

        DB::transaction(fn () => $this->delete());

        DB::afterCommit(function () use ($key): void {
            $disk = Storage::disk(self::DISK);

            if ($disk->delete($key) === false || $disk->exists($key)) {
                report(new RuntimeException("The file of a deleted document could not be removed: [{$key}]."));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'client_can_download' => 'boolean',
        ];
    }
}
