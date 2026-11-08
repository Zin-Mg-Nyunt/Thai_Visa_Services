<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
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
            $clientService->documents()->create([
                'title' => trim($request->validated('title')),
                'storage_key' => $key,
                'mime' => $detected['mime'],
                'size' => $request->file('file')->getSize(),
                'client_can_download' => false,
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            $disk->delete($key);

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document uploaded.')]);

        return to_route('admin.clients.show', $client);
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

        $document->update($data);

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
            $document->replaceFile($key, ['mime' => $detected['mime'], 'size' => $request->file('file')->getSize()], $request->user()->id);
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

        $document->erase();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document deleted.')]);

        return to_route('admin.clients.show', $client);
    }
}
