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
     * Only a service that has documents (for example Work Permit) can take files. Job Search records do not take them
     * from the client page: their documents belong to the job order work of a later phase and are never shown to clients.
     */
    public function create(User $user, ClientService $clientService): bool
    {
        $service = $clientService->service;

        return $user->isSuperadmin() && $service->has_documents && ! $service->isJobSearch();
    }

    public function view(User $user, Document $document): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Document $document): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->isSuperadmin();
    }
}
