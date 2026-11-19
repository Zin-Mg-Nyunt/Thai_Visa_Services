<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Site\IdentifyWebsiteImage;
use App\Actions\Site\SiteSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppearanceRequest;
use App\Models\SiteSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The Website appearance screen (Section 4.11): the logo, the favicon, the hero image and the hero text.
 *
 * Saving keeps files and database in step. The new files are stored first; then, in one transaction, the row is locked and the
 * new keys and the text are written; only after that commit are the replaced files deleted. If anything fails, the new files are
 * removed and the previous keys and text stay. A file that cannot be deleted is reported and never fails the save: it is left
 * behind with no record pointing to it.
 */
class AppearanceController extends Controller
{
    /** The request field of each image and the column that holds its storage key. */
    private const IMAGES = ['logo' => 'logo_key', 'favicon' => 'favicon_key', 'hero_image' => 'hero_image_key'];

    public function edit(SiteSettings $settings): Response
    {
        Gate::authorize('manage-site-settings');

        $setting = $settings->get();

        return Inertia::render('admin/settings/Appearance', [
            'appearance' => [
                'logo_url' => $setting->imageUrl($setting->logo_key),
                'favicon_url' => $setting->imageUrl($setting->favicon_key),
                'hero_image_url' => $setting->imageUrl($setting->hero_image_key),
                'hero_heading' => $setting->hero_heading ?? '',
                'hero_heading_my' => $setting->hero_heading_my ?? '',
                'hero_description' => $setting->hero_description ?? '',
                'hero_description_my' => $setting->hero_description_my ?? '',
            ],
        ]);
    }

    public function update(UpdateAppearanceRequest $request): RedirectResponse
    {
        $disk = Storage::disk(SiteSetting::DISK);
        /** @var array<string, string> $stored column => the new key, for every file stored in this request */
        $stored = [];

        try {
            foreach (self::IMAGES as $field => $column) {
                $key = $request->newImageKey($field);

                if ($key === null) {
                    continue;
                }

                if ($disk->putFileAs(IdentifyWebsiteImage::DIRECTORY, $request->file($field), basename($key)) === false) {
                    throw ValidationException::withMessages([$field => __('The file could not be saved.')]);
                }

                $stored[$column] = $key;
            }

            $this->ensureRowExists();
            $replaced = DB::transaction(fn (): array => $this->save($request, $stored));
        } catch (Throwable $exception) {
            foreach ($stored as $key) {
                $this->removeFile($key);
            }

            throw $exception;
        }

        // The save is committed: the replaced files are no longer used by anything.
        foreach ($replaced as $key) {
            $this->removeFile($key);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appearance saved.')]);

        return to_route('admin.settings.appearance.edit');
    }

    /**
     * The settings row is created once, by whoever saves first. An admin who saves at the same moment finds it already there.
     */
    private function ensureRowExists(): void
    {
        if (SiteSetting::query()->whereKey(SiteSetting::ID)->exists()) {
            return;
        }

        try {
            SiteSetting::query()->forceCreate(['id' => SiteSetting::ID]);
        } catch (UniqueConstraintViolationException) {
            // Another admin created it a moment ago.
        }
    }

    /**
     * Write the keys and the text under a lock on the settings row, and say which old files they replaced.
     *
     * @param  array<string, string>  $stored
     * @return array<int, string> the keys of the files that are no longer used
     */
    private function save(UpdateAppearanceRequest $request, array $stored): array
    {
        $setting = SiteSetting::query()->lockForUpdate()->findOrFail(SiteSetting::ID);
        $replaced = [];

        foreach ($stored as $column => $key) {
            if ($setting->{$column} !== null) {
                $replaced[] = $setting->{$column};
            }

            $setting->{$column} = $key;
        }

        // The hero image is optional, so it can be removed, unless a new one is sent in the same save.
        if ($request->boolean('remove_hero_image') && ! isset($stored['hero_image_key']) && $setting->hero_image_key !== null) {
            $replaced[] = $setting->hero_image_key;
            $setting->hero_image_key = null;
        }

        foreach (UpdateAppearanceRequest::TEXT_FIELDS as $field) {
            if ($request->has($field)) {
                $value = $request->validated($field);
                $setting->{$field} = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        $setting->updated_by = $request->user()->id;
        $setting->save();

        return $replaced;
    }

    /**
     * Delete a file of the public disk. A failure is reported and never stops the request.
     */
    private function removeFile(string $key): void
    {
        try {
            $disk = Storage::disk(SiteSetting::DISK);

            if ($disk->delete($key) === false || $disk->exists($key)) {
                report(new RuntimeException("A website image could not be removed: [{$key}]."));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
