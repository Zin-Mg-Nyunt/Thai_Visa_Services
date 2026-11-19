<?php

use App\Actions\Site\SiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Website content screen (Section 4.10). See App\Http\Controllers\Admin\WebsiteContentController.
 */
beforeEach(function () {
    app()->setLocale('en');
    Cache::forget(SiteSettings::CACHE_KEY);
    $this->admin = User::factory()->create();
});

/** @param  array<string, mixed>  $data */
function saveContent(mixed $test, array $data = [], ?User $as = null): TestResponse
{
    return $test->actingAs($as ?? $test->admin)->postJson(route('admin.settings.content.update'), $data);
}

/** A saved row that already has a logo and a favicon, so it can be published. */
function publishableSettings(array $state = []): SiteSetting
{
    $setting = SiteSetting::factory()->create($state);
    $setting->forceFill(['logo_key' => 'site/logo.png', 'favicon_key' => 'site/fav.png'])->save();

    return $setting;
}

describe('who may use it', function () {
    test('guests are sent to the login page', function () {
        $this->get(route('admin.settings.content.edit'))->assertRedirect(route('login'));
        $this->post(route('admin.settings.content.update'), [])->assertRedirect(route('login'));
    });

    test('a user without the superadmin role gets 403 and nothing is stored', function () {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

        $this->actingAs($user->fresh())->get(route('admin.settings.content.edit'))->assertForbidden();
        saveContent($this, ['agency_name' => 'Nope'], $user->fresh())->assertForbidden();

        expect(SiteSetting::query()->count())->toBe(0);
    });
});

describe('the page', function () {
    test('with nothing saved it shows empty fields, no statistics approved and not published', function () {
        $this->actingAs($this->admin)->get(route('admin.settings.content.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/WebsiteContent')
            ->where('content.agency_name', '')->where('content.mission_my', '')->where('content.phone', '')
            ->where('content.social_links', ['facebook' => '', 'instagram' => '', 'tiktok' => '', 'youtube' => ''])
            ->where('content.approved_statistics', [])->where('content.is_published', false)
            ->where('has_logo', false)->where('has_favicon', false));
    });

    test('it sends what is saved, and only whether a logo and a favicon exist, never their keys', function () {
        publishableSettings(['agency_name' => 'PaingThu', 'social_links' => ['facebook' => 'https://facebook.com/x']]);

        $this->actingAs($this->admin)->get(route('admin.settings.content.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('content.agency_name', 'PaingThu')->where('content.social_links.facebook', 'https://facebook.com/x')
            ->where('has_logo', true)->where('has_favicon', true)
            ->missing('content.logo_key')->missing('logo_key'));
    });
});

describe('saving', function () {
    test('every field is saved, trimmed, with who saved it, and the first save creates the row', function () {
        saveContent($this, [
            'agency_name' => '  PaingThu  ', 'agency_name_my' => 'ပိုင်သူ', 'overview' => 'We help.', 'overview_my' => null,
            'experience' => 'Years.', 'mission' => 'Our mission', 'mission_my' => 'ရည်မှန်းချက်', 'how_we_help' => 'By phone.',
            'address' => '1 Road, Bangkok', 'address_my' => 'ဘန်ကောက်', 'phone' => '+66 2 123 4567', 'email' => 'hello@example.test',
            'messaging_link' => 'https://line.me/ti/p/abc',
            'social_links' => ['facebook' => 'https://facebook.com/x', 'instagram' => ' https://instagram.com/y ', 'tiktok' => null, 'youtube' => ''],
            'approved_statistics' => ['clients_served'],
        ])->assertRedirect(route('admin.settings.content.edit'));

        $setting = SiteSetting::query()->sole();

        expect($setting->agency_name)->toBe('PaingThu')->and($setting->agency_name_my)->toBe('ပိုင်သူ')
            ->and($setting->overview)->toBe('We help.')->and($setting->overview_my)->toBeNull()
            ->and($setting->mission_my)->toBe('ရည်မှန်းချက်')->and($setting->address)->toBe('1 Road, Bangkok')
            ->and($setting->phone)->toBe('+66 2 123 4567')->and($setting->email)->toBe('hello@example.test')
            ->and($setting->messaging_link)->toBe('https://line.me/ti/p/abc')
            ->and($setting->social_links)->toBe(['facebook' => 'https://facebook.com/x', 'instagram' => 'https://instagram.com/y'])
            ->and($setting->approved_statistics)->toBe(['clients_served'])
            ->and($setting->updated_by)->toBe($this->admin->id)->and($setting->is_published)->toBeFalse();
    });

    test('a save that sends only some fields leaves the rest as they were', function () {
        $setting = publishableSettings(['agency_name' => 'Old', 'mission' => 'Keep me', 'phone' => '081', 'social_links' => ['facebook' => 'https://f.test/a'], 'approved_statistics' => ['clients_served']]);

        saveContent($this, ['agency_name' => 'New'])->assertRedirect();

        $fresh = $setting->fresh();

        expect($fresh->agency_name)->toBe('New')->and($fresh->mission)->toBe('Keep me')->and($fresh->phone)->toBe('081')
            ->and($fresh->social_links)->toBe(['facebook' => 'https://f.test/a'])->and($fresh->approved_statistics)->toBe(['clients_served'])
            ->and($fresh->logo_key)->toBe('site/logo.png');
    });

    test('an empty text is stored as empty, so it is hidden on the website, and every statistic and link can be cleared', function () {
        $setting = publishableSettings(['agency_name' => 'Old', 'social_links' => ['facebook' => 'https://f.test/a'], 'approved_statistics' => ['clients_served', 'services_provided']]);

        saveContent($this, ['agency_name' => '   ', 'social_links' => [], 'approved_statistics' => []])->assertRedirect();

        $fresh = $setting->fresh();

        expect($fresh->agency_name)->toBeNull()->and($fresh->social_links)->toBeNull()->and($fresh->approved_statistics)->toBeNull();
    });

    test('the saved content reaches the cached settings only after the save, and the row is not duplicated', function () {
        SiteSetting::factory()->create(['agency_name' => 'Before']);
        expect(app(SiteSettings::class)->get()->agency_name)->toBe('Before');

        saveContent($this, ['agency_name' => 'After']);
        saveContent($this, ['agency_name' => 'After again']);

        expect(app(SiteSettings::class)->get()->agency_name)->toBe('After again')->and(SiteSetting::query()->count())->toBe(1);
    });

    test('the Burmese messages and attribute names are used when the language is Burmese', function () {
        app()->setLocale('my');

        $errors = saveContent($this, ['email' => 'nope', 'messaging_link' => 'ftp://x'])->assertUnprocessable()->json('errors');

        expect($errors['email'])->toBe(['မှန်ကန်သော အီးမေးလ်လိပ်စာ ထည့်ပါ။'])
            ->and($errors['messaging_link'])->toBe(['http:// သို့မဟုတ် https:// ဖြင့်စသော ဝက်ဘ်လိပ်စာ အပြည့်အစုံ ထည့်ပါ။']);
    });
});

describe('validation', function () {
    test('every link must be a full http or https address, so it can never be a script or another kind of address', function (string $link) {
        saveContent($this, ['messaging_link' => $link, 'social_links' => ['facebook' => $link]])
            ->assertUnprocessable()->assertJsonValidationErrors(['messaging_link', 'social_links.facebook']);

        expect(SiteSetting::query()->count())->toBe(0);
    })->with([
        'javascript' => ['javascript:alert(1)'],
        'data' => ['data:text/html,<script>alert(1)</script>'],
        'ftp' => ['ftp://example.test/file'],
        'tel' => ['tel:+66812345678'],
        'no scheme' => ['example.test'],
        'text' => ['not a link'],
    ]);

    test('http and https links are accepted', function (string $link) {
        saveContent($this, ['messaging_link' => $link, 'social_links' => ['youtube' => $link]])->assertRedirect();
    })->with(['https' => ['https://line.me/ti/p/abc'], 'http' => ['http://example.test/page?x=1']]);

    test('an unknown social network is refused', function () {
        saveContent($this, ['social_links' => ['myspace' => 'https://example.test']])->assertUnprocessable()->assertJsonValidationErrors('social_links');
    });

    test('the email and the phone number must look right', function () {
        saveContent($this, ['email' => 'not-an-email', 'phone' => 'call me <b>'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);
        saveContent($this, ['phone' => '+66 (0) 2-123.4567'])->assertRedirect();
    });

    test('the length limits hold, in both languages', function () {
        saveContent($this, ['agency_name' => str_repeat('a', 121), 'mission_my' => str_repeat('မ', 1001), 'address' => str_repeat('a', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors(['agency_name', 'mission_my', 'address']);
        saveContent($this, ['agency_name' => str_repeat('a', 120), 'mission_my' => str_repeat('မ', 1000)])->assertRedirect();
    });

    test('only the two real statistics can be approved', function () {
        saveContent($this, ['approved_statistics' => ['clients_served', 'profit']])->assertUnprocessable()->assertJsonValidationErrors('approved_statistics.1');
    });
});

/** @param  array<string, mixed>  $data */
function publishWebsite(mixed $test, array $data = [], ?User $as = null): TestResponse
{
    return $test->actingAs($as ?? $test->admin)->postJson(route('admin.settings.content.publish'), $data);
}

describe('publishing', function () {
    test('it is its own action: guests are redirected and a user without the superadmin role gets 403', function () {
        $this->postJson(route('admin.settings.content.publish'), ['published' => true])->assertUnauthorized();

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);
        publishWebsite($this, ['published' => true], $user->fresh())->assertForbidden();

        expect(SiteSetting::query()->count())->toBe(0);
    });

    test('the website cannot be published without a logo and a favicon, and nothing is changed', function (array $keys) {
        $setting = SiteSetting::factory()->create(['agency_name' => 'Old']);
        $setting->forceFill($keys)->save();

        publishWebsite($this, ['published' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['published' => 'Upload a logo and a favicon before publishing the website.']);

        expect($setting->fresh()->is_published)->toBeFalse()->and($setting->fresh()->agency_name)->toBe('Old');
    })->with([
        'neither' => [[]],
        'only a logo' => [['logo_key' => 'site/logo.png']],
        'only a favicon' => [['favicon_key' => 'site/fav.png']],
    ]);

    test('with a logo and a favicon it can be published and unpublished, who did it is saved, and the content is left alone', function () {
        $setting = publishableSettings(['agency_name' => 'Keep', 'mission' => 'Keep mission']);

        publishWebsite($this, ['published' => true])->assertRedirect(route('admin.settings.content.edit'));
        expect($setting->fresh()->is_published)->toBeTrue()->and($setting->fresh()->updated_by)->toBe($this->admin->id);

        publishWebsite($this, ['published' => false])->assertRedirect(route('admin.settings.content.edit'));
        expect($setting->fresh()->is_published)->toBeFalse()->and($setting->fresh()->agency_name)->toBe('Keep')->and($setting->fresh()->mission)->toBe('Keep mission');
    });

    test('unpublishing needs no logo or favicon', function () {
        $setting = SiteSetting::factory()->create(['is_published' => true]);

        publishWebsite($this, ['published' => false])->assertRedirect();

        expect($setting->fresh()->is_published)->toBeFalse();
    });

    test('the answer must be true or false', function () {
        publishableSettings();

        publishWebsite($this, [])->assertUnprocessable()->assertJsonValidationErrors('published');
        publishWebsite($this, ['published' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('published');
    });

    test('publishing clears the cached settings, and a refused attempt keeps them', function () {
        $setting = publishableSettings();
        expect(app(SiteSettings::class)->get()->is_published)->toBeFalse();

        publishWebsite($this, ['published' => true])->assertRedirect();
        expect(app(SiteSettings::class)->get()->is_published)->toBeTrue();

        $setting->fresh()->forceFill(['logo_key' => null, 'is_published' => false])->save();
        expect(app(SiteSettings::class)->get()->is_published)->toBeFalse();

        publishWebsite($this, ['published' => true])->assertUnprocessable();
        expect(Cache::has(SiteSettings::CACHE_KEY))->toBeTrue()->and(app(SiteSettings::class)->get()->is_published)->toBeFalse();
    });

    test('saving the content never changes whether the website is published, even if the switch is sent', function () {
        $setting = publishableSettings(['is_published' => true]);

        saveContent($this, ['agency_name' => 'x', 'is_published' => false])->assertRedirect();
        expect($setting->fresh()->is_published)->toBeTrue();

        $setting->forceFill(['is_published' => false])->save();
        saveContent($this, ['agency_name' => 'y', 'is_published' => true])->assertRedirect();
        expect($setting->fresh()->is_published)->toBeFalse();
    });

    test('the page has a Publish button with a confirmation, and no tick box', function () {
        $page = file_get_contents(resource_path('js/pages/admin/settings/WebsiteContent.vue'));

        expect($page)->toContain('data-test="publish-button"')->toContain('<ConfirmDialog')->toContain('publish().url')
            ->toContain('form.isDirty')->toContain('data-test="publish-state"')
            ->not->toContain('publish-switch')->not->toContain('is_published"');
    });
});

describe('when something goes wrong', function () {
    test('a database failure keeps the previous content', function () {
        $setting = SiteSetting::factory()->create(['agency_name' => 'Old', 'mission' => 'Old mission']);
        $this->withoutExceptionHandling();
        SiteSetting::saving(fn () => throw new RuntimeException('database is down'));

        expect(fn () => saveContent($this, ['agency_name' => 'Lost', 'mission' => 'Lost too']))->toThrow(RuntimeException::class, 'database is down');

        expect($setting->fresh()->agency_name)->toBe('Old')->and($setting->fresh()->mission)->toBe('Old mission');
    });
});
