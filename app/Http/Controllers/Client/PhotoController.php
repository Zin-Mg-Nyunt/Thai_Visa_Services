<?php

namespace App\Http\Controllers\Client;

use App\Actions\Client\ClientPortalSession;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    /**
     * Send a client's photo. The link must be signed and unexpired, and the visitor must also be
     * allowed to see this client: a superadmin, or this client signed in with their PIN.
     * A signed link copied to someone else's browser therefore shows nothing. The browser never keeps
     * a copy (no-store), so after a client signs out on a shared phone the photo cannot be seen again.
     */
    public function show(Request $request, Client $client): StreamedResponse
    {
        $allowed = $request->user()?->can('view', $client)
            || (new ClientPortalSession($request->session()))->isSignedIn($client);

        abort_unless($allowed, 403);

        $disk = Storage::disk(Client::PHOTO_DISK);

        abort_if($client->photo_path === null || ! $disk->exists($client->photo_path), 404);

        return $disk->response($client->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
