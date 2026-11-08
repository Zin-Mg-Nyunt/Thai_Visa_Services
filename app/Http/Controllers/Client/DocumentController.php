<?php

namespace App\Http\Controllers\Client;

use App\Actions\Document\DeliverDocument;
use App\Actions\Document\LogDocumentAccess;
use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    /**
     * Download one of the signed-in client's documents. The route is rate limited, and every attempt that reaches this
     * point is written to the access history, allowed or denied:
     * - another client's document: 404, so its existence is not revealed (logged as not_owner);
     * - the client's own document that is not offered to them (cancelled service, Job Search, no documents, file gone): 404
     *   (logged as not_available);
     * - their own document that is still locked: 403 (logged as locked);
     * - otherwise the file is delivered (logged as allowed).
     * The document is looked up by its id alone, so the ownership check, and its log row, are done here.
     */
    public function download(
        Request $request,
        Client $client,
        Document $document,
        LogDocumentAccess $log,
        DeliverDocument $deliver,
    ): Response {
        $document->load('clientService.service');
        $clientService = $document->clientService;
        $service = $clientService->service;

        $attempt = fn (DocumentAccessOutcome $outcome, ?DocumentDenyReason $reason) => $log(
            $document, DocumentActor::Client, $client->id, $outcome, $reason, $request->ip(),
        );

        if ($clientService->client_id !== $client->id) {
            $attempt(DocumentAccessOutcome::Denied, DocumentDenyReason::NotOwner);

            abort(404);
        }

        if ($clientService->isCancelled() || ! $service->has_documents || $service->isJobSearch()) {
            $attempt(DocumentAccessOutcome::Denied, DocumentDenyReason::NotAvailable);

            abort(404);
        }

        if (! $document->isUnlocked()) {
            $attempt(DocumentAccessOutcome::Denied, DocumentDenyReason::Locked);

            abort(403);
        }

        if (! $deliver->hasFile($document)) {
            report(new \RuntimeException("The file of document [{$document->id}] is missing from the disk."));
            $attempt(DocumentAccessOutcome::Denied, DocumentDenyReason::NotAvailable);

            abort(404);
        }

        $attempt(DocumentAccessOutcome::Allowed, null);

        return $deliver->response($document);
    }
}
