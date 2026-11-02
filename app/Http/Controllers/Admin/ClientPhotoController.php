<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClientPhotoRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ClientPhotoController extends Controller
{
    /**
     * Save a new profile photo on the private disk and remove the one it replaces.
     */
    public function update(UpdateClientPhotoRequest $request, Client $client): RedirectResponse
    {
        $oldPath = $client->photo_path;

        $client->forceFill([
            'photo_path' => $request->file('photo')->storeAs(Client::PHOTO_DIRECTORY, Str::uuid().'.jpg', Client::PHOTO_DISK),
        ])->save();

        if ($oldPath !== null) {
            Storage::disk(Client::PHOTO_DISK)->delete($oldPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo saved.')]);

        return to_route('admin.clients.show', $client);
    }

    /**
     * Remove the profile photo.
     */
    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        $client->erasePhoto();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed.')]);

        return to_route('admin.clients.show', $client);
    }
}
