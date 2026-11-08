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
     * Only a service that has documents (for example Work Permit) can take files.
     */
    public function create(User $user, ClientService $clientService): bool
    {
        return $user->isSuperadmin() && $clientService->service->has_documents;
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
