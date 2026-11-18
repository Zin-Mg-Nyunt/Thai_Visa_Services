<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Document\DeliverDocument;
use App\Actions\Document\LogDocumentAccess;
use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DocumentController extends Controller
{
    /**
     * Save a file on the private disk and add it to the client's service. The file is judged by its content, is stored
     * under a random name, and the title the admin typed is only text in the database: it is never part of a path.
     */
    public function store(StoreDocumentRequest $request, Client $client, ClientService $clientService): RedirectResponse
    {
        $detected = $request->detected();
        abort_if($detected === null, 422);

        $disk = Storage::disk(Document::DISK);
        $key = Document::DIRECTORY.'/'.Str::uuid().'.'.$detected['extension'];

        $stored = $disk->putFileAs(Document::DIRECTORY, $request->file('file'), basename($key));

        if ($stored === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be saved.')]);
        }

        try {
            $this->whileLocked($client, $clientService, function (ClientService $locked) use ($request, $key, $detected): void {
                Gate::authorize('create', [Document::class, $locked]);

                $locked->documents()->create([
                    'title' => trim($request->validated('title')),
                    'storage_key' => $key,
                    'mime' => $detected['mime'],
                    'size' => $request->file('file')->getSize(),
                    'client_can_download' => false,
                    'uploaded_by' => $request->user()->id,
                ]);
            });
        } catch (Throwable $exception) {
            $disk->delete($key);

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document uploaded.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Let the superadmin open a document file. The admin is not held back by a lock, but the attempt is logged like every
     * other one, so the history shows who opened what.
     */
    public function download(
        Request $request,
        Client $client,
        ClientService $clientService,
        Document $document,
        LogDocumentAccess $log,
        DeliverDocument $deliver,
    ): Response {
        Gate::authorize('view', $document);

        $document->setRelation('clientService', $clientService);

        if (! $deliver->hasFile($document)) {
            report(new \RuntimeException("The file of document [{$document->id}] is missing from the disk."));
            $log($document, DocumentActor::Admin, $request->user()->id, DocumentAccessOutcome::Denied, DocumentDenyReason::NotAvailable, $request->ip());

            abort(404);
        }

        $log($document, DocumentActor::Admin, $request->user()->id, DocumentAccessOutcome::Allowed, null, $request->ip());

        return $deliver->response($document);
    }

    /**
     * Rename a document, change its type, or release it to the client (or take the release back).
     */
    public function update(UpdateDocumentRequest $request, Client $client, ClientService $clientService, Document $document): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        $this->whileLocked($client, $clientService, function (ClientService $locked) use ($document, $data): void {
            $this->lockDocument($document, $locked);
            Gate::authorize('update', $document);

            $document->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document updated.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Replace the file of a document. The new file goes through the same checks as an upload and gets a new random name.
     * The title, the release setting and the access history stay; the old file is removed once the new one is saved.
     */
    public function replace(ReplaceDocumentRequest $request, Client $client, ClientService $clientService, Document $document): RedirectResponse
    {
        $detected = $request->detected();
        abort_if($detected === null, 422);

        $disk = Storage::disk(Document::DISK);
        $key = Document::DIRECTORY.'/'.Str::uuid().'.'.$detected['extension'];

        if ($disk->putFileAs(Document::DIRECTORY, $request->file('file'), basename($key)) === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be saved.')]);
        }

        try {
            $this->whileLocked($client, $clientService, function (ClientService $locked) use ($document, $key, $detected, $request): void {
                $this->lockDocument($document, $locked);
                Gate::authorize('update', $document);

                $document->replaceFile($key, ['mime' => $detected['mime'], 'size' => $request->file('file')->getSize()], $request->user()->id);
            });
        } catch (Throwable $exception) {
            $disk->delete($key);

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File replaced.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Delete a document: its record and its file. The access history stays.
     */
    public function destroy(Client $client, ClientService $clientService, Document $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $this->whileLocked($client, $clientService, function (ClientService $locked) use ($document): void {
            $this->lockDocument($document, $locked);
            Gate::authorize('delete', $document);

            $document->erase();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document deleted.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Run a document change with the Person and the service locked and re-read, in the order Person, then service (then the
     * document). The request was checked before the lock, but the engagement can change in between: a Cancel that has committed,
     * or a Person who has been deleted, must stop an upload or an edit that was already on its way, so the work re-checks the
     * policy on the locked service. A deleted Person is a 404. No file is touched while the locks are held: the new file is
     * stored before, and removed by the caller if this fails; an old file is removed after the commit.
     *
     * @param  Closure(ClientService): void  $work
     */
    private function whileLocked(Client $client, ClientService $clientService, Closure $work): void
    {
        DB::transaction(function () use ($client, $clientService, $work): void {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            $locked = ClientService::query()->whereKey($clientService->id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            $locked->load('service');

            $work($locked);
        });
    }

    /**
     * Re-read the document under a lock, still the one of this service, and give it the locked service for the policy check.
     */
    private function lockDocument(Document $document, ClientService $locked): void
    {
        $fresh = Document::query()->whereKey($document->id)->where('client_service_id', $locked->id)->lockForUpdate()->firstOrFail();

        $document->setRawAttributes($fresh->getAttributes(), true);
        $document->setRelation('clientService', $locked);
    }
}
