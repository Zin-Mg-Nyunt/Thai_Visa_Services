<?php

namespace App\Models;

use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use Database\Factories\DocumentAccessLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row for every attempt to open a document, allowed or denied. Rows are only added, and they stay when the document is
 * deleted: the client id and the title are copied here for that reason. For a download from Spaces the row records that the
 * signed link was issued, not that the download finished.
 *
 * @property int $id
 * @property int|null $document_id
 * @property int|null $client_id
 * @property string|null $document_title
 * @property DocumentAccessOutcome $outcome
 * @property DocumentDenyReason|null $reason
 * @property DocumentActor $actor_type
 * @property int|null $actor_id
 * @property string|null $ip
 * @property Carbon|null $created_at
 */
#[Fillable(['document_id', 'client_id', 'document_title', 'outcome', 'reason', 'actor_type', 'actor_id', 'ip'])]
class DocumentAccessLog extends Model
{
    /** @use HasFactory<DocumentAccessLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => DocumentAccessOutcome::class,
            'reason' => DocumentDenyReason::class,
            'actor_type' => DocumentActor::class,
        ];
    }
}
