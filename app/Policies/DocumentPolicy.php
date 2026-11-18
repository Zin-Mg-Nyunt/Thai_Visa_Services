<?php

namespace App\Policies;

use App\Models\ClientService;
use App\Models\Document;
use App\Models\User;

/**
 * What the superadmin may do with documents. A client signed in to the portal is not a User; the client download is
 * checked separately against the client's own services and Document::isUnlocked().
 */
class DocumentPolicy
{
    /**
     * Upload to a service. An ordinary service takes files only when it is set up for documents. A Job Search engagement always
     * takes them, while it is In Progress or Complete (they are the admin's own papers for the job search work); once it is
     * Cancelled its documents are read-only. They are never shown to the client either way.
     */
    public function create(User $user, ClientService $clientService): bool
    {
        if (! $user->isSuperadmin()) {
            return false;
        }

        $service = $clientService->service;

        return $service->isJobSearch() ? ! $clientService->isCancelled() : $service->has_documents;
    }

    public function view(User $user, Document $document): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Document $document): bool
    {
        return $user->isSuperadmin() && ! $this->isReadOnly($document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->isSuperadmin() && ! $this->isReadOnly($document);
    }

    /**
     * The documents of a Cancelled Job Search engagement can be viewed and downloaded, but not renamed, replaced or deleted. The
     * documents of any other service are not locked by its status.
     */
    private function isReadOnly(Document $document): bool
    {
        $clientService = $document->clientService;

        return $clientService->service->isJobSearch() && $clientService->isCancelled();
    }
}
