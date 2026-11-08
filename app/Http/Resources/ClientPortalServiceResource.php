<?php

namespace App\Http\Resources;

use App\Models\ClientService;
use App\Models\Document;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client may see about one of their own services: the service and sub-service titles, the status, and for a
 * service that is not cancelled the fee, the money received, the balance still to pay and the payment status.
 *
 * A Work Permit style service (one that takes documents, and is not Job Search) also lists its documents by title, each
 * Downloadable or Locked. A cancelled service shows its status only. Refunds, the amount the agency keeps, dates, remarks, the payment list, the
 * agency's ids and the file keys of documents are never sent to the client. Money goes as decimal strings.
 *
 * @mixin ClientService
 */
class ClientPortalServiceResource extends JsonResource
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service' => $this->titles($this->service),
            'sub_service' => $this->subService === null ? null : $this->titles($this->subService),
            'status' => $this->status->value,
            $this->mergeWhen(! $this->isCancelled(), fn () => [
                'fee' => $this->fee,
                'received' => $this->received(),
                'remaining' => $this->remaining(),
                'payment_status' => $this->paymentStatus()->value,
            ]),
            $this->mergeWhen($this->listsDocuments(), fn () => [
                // Only the id, the title and whether it can be downloaded. The file key, type, size and the admin's
                // release flag never reach the client.
                'documents' => $this->documents->map(fn (Document $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'unlocked' => $document->client_can_download || $this->unlocksDocuments(),
                ])->values()->all(),
            ]),
        ];
    }

    /**
     * Only a service that takes documents lists them, never Job Search (its documents are for the admin only), and never
     * a cancelled service (the client sees its status only).
     */
    private function listsDocuments(): bool
    {
        return $this->relationLoaded('documents')
            && ! $this->isCancelled()
            && $this->service->has_documents
            && ! $this->service->isJobSearch();
    }

    /**
     * @return array{title: string|null, title_my: string|null}
     */
    private function titles(Service $service): array
    {
        return ['title' => $service->title, 'title_my' => $service->title_my];
    }
}
