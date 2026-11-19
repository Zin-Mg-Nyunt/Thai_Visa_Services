<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Site\SiteSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublishWebsiteRequest;
use App\Http\Requests\UpdateWebsiteContentRequest;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Website content screen (Section 4.10): the agency texts in English and Burmese, the contact details, the social links, which
 * statistics may be shown. Publishing the website is a separate action (it cannot be done without a logo and a favicon, Section 4.11).
 *
 * A save is one transaction under a lock on the settings row; the cached settings are cleared once it has committed. If anything
 * fails, the previous content stays.
 */
class WebsiteContentController extends Controller
{
    public function edit(SiteSettings $settings): Response
    {
        Gate::authorize('manage-site-settings');

        $setting = $settings->get();
        $text = [];

        foreach (array_keys(UpdateWebsiteContentRequest::TEXT_LIMITS) as $field) {
            $text[$field] = $setting->{$field} ?? '';
            $text[$field.'_my'] = $setting->{$field.'_my'} ?? '';
        }

        return Inertia::render('admin/settings/WebsiteContent', [
            'content' => [
                ...$text,
                'phone' => $setting->phone ?? '',
                'email' => $setting->email ?? '',
                'messaging_link' => $setting->messaging_link ?? '',
                'social_links' => collect(UpdateWebsiteContentRequest::SOCIAL_PLATFORMS)
                    ->mapWithKeys(fn (string $platform): array => [$platform => $setting->social_links[$platform] ?? ''])
                    ->all(),
                'approved_statistics' => $setting->approved_statistics ?? [],
                'is_published' => $setting->is_published,
            ],
            // Publishing needs both; the page shows which one is missing. Only whether they exist is sent, never the keys.
            'has_logo' => $setting->logo_key !== null,
            'has_favicon' => $setting->favicon_key !== null,
        ]);
    }

    public function update(UpdateWebsiteContentRequest $request): RedirectResponse
    {
        SiteSetting::ensureExists();

        DB::transaction(function () use ($request): void {
            $setting = SiteSetting::query()->lockForUpdate()->findOrFail(SiteSetting::ID);

            foreach (array_keys(UpdateWebsiteContentRequest::TEXT_LIMITS) as $field) {
                foreach ([$field, $field.'_my'] as $column) {
                    if ($request->has($column)) {
                        $setting->{$column} = $this->clean($request->validated($column));
                    }
                }
            }

            foreach (['phone', 'email', 'messaging_link'] as $column) {
                if ($request->has($column)) {
                    $setting->{$column} = $this->clean($request->validated($column));
                }
            }

            if ($request->has('social_links')) {
                $links = [];

                foreach ((array) $request->validated('social_links') as $platform => $link) {
                    $link = $this->clean($link);

                    if ($link !== null) {
                        $links[$platform] = $link;
                    }
                }

                $setting->social_links = $links === [] ? null : $links;
            }

            if ($request->has('approved_statistics')) {
                $statistics = array_values(array_unique($request->validated('approved_statistics') ?? []));

                $setting->approved_statistics = $statistics === [] ? null : $statistics;
            }

            $setting->updated_by = $request->user()->id;
            $setting->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Website content saved.')]);

        return to_route('admin.settings.content.edit');
    }

    /**
     * Publish or unpublish the website. Publishing needs a logo and a favicon (Section 4.11); this is checked on the locked row,
     * so the answer is the one that is saved. The content itself is not touched: what becomes public is what was last saved.
     */
    public function publish(PublishWebsiteRequest $request): RedirectResponse
    {
        SiteSetting::ensureExists();

        $publish = $request->boolean('published');

        DB::transaction(function () use ($request, $publish): void {
            $setting = SiteSetting::query()->lockForUpdate()->findOrFail(SiteSetting::ID);

            if ($publish && ($setting->logo_key === null || $setting->favicon_key === null)) {
                throw ValidationException::withMessages(['published' => __('Upload a logo and a favicon before publishing the website.')]);
            }

            $setting->is_published = $publish;
            $setting->updated_by = $request->user()->id;
            $setting->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $publish ? __('Website published.') : __('Website unpublished.')]);

        return to_route('admin.settings.content.edit');
    }

    /** A text without spaces around it, or null when nothing is left. */
    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
