<?php

namespace App\Actions\Document;

use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use App\Models\Document;
use App\Models\DocumentAccessLog;

/**
 * Writes one row to the document access history for an attempt to open a document, allowed or denied. The row keeps its
 * own copies of the owner client's id and the document title, so it stays readable after the document is deleted. For a
 * download from Spaces the row records that the signed link was issued, not that the download finished.
 */
class LogDocumentAccess
{
    /**
     * @param  int|null  $actorId  the client who asked (a client signed in to the portal) or the superadmin's user id
     */
    public function __invoke(
        Document $document,
        DocumentActor $actor,
        ?int $actorId,
        DocumentAccessOutcome $outcome,
        ?DocumentDenyReason $reason,
        ?string $ip,
    ): DocumentAccessLog {
        return DocumentAccessLog::create([
            'document_id' => $document->id,
            'client_id' => $document->clientService->client_id,
            'document_title' => $document->title,
            'outcome' => $outcome,
            'reason' => $reason,
            'actor_type' => $actor,
            'actor_id' => $actorId,
            'ip' => $ip,
        ]);
    }
}
