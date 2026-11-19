<?php

use App\Actions\Site\SiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Website appearance screen (Section 4.11). See App\Http\Controllers\Admin\AppearanceController.
 */
beforeEach(function () {
    app()->setLocale('en');
    Storage::fake(SiteSetting::DISK);
    Cache::forget(SiteSettings::CACHE_KEY);
    $this->admin = User::factory()->create();
});

/** A real image file whose content is `$kind`. */
function appearanceFile(string $kind, string $name): UploadedFile
{
    $bytes = match ($kind) {
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
        'jpg' => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
        'webp' => base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA=='),
        'ico' => "\x00\x00\x01\x00\x01\x00\x01\x01\x00\x00\x01\x00\x20\x00\x30\x00\x00\x00\x16\x00\x00\x00"
            ."\x28\x00\x00\x00\x01\x00\x00\x00\x02\x00\x00\x00\x01\x00\x20\x00\x00\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            ."\xff\x00\x00\xff\x00\x00\x00\x00",
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    };
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** @param  array<string, mixed>  $data */
function saveAppearance(mixed $test, array $data = [], ?User $as = null): TestResponse
{
    return $test->actingAs($as ?? $test->admin)->post(route('admin.settings.appearance.update'), $data);
}

/** @return array<int, string> every file on the public disk */
function websiteFiles(): array
{
    return Storage::disk(SiteSetting::DISK)->allFiles();
}

/** A saved row that already has all three images, each with a file on the disk. */
function savedAppearance(): SiteSetting
{
    $disk = Storage::disk(SiteSetting::DISK);
    $keys = ['logo_key' => 'site/old-logo.png', 'favicon_key' => 'site/old-favicon.png', 'hero_image_key' => 'site/old-hero.jpg'];

    foreach ($keys as $key) {
        $disk->put($key, 'old');
    }

    $setting = SiteSetting::factory()->create(['hero_heading' => 'Old heading', 'hero_description' => 'Old description']);
    $setting->forceFill($keys)->save();

    return $setting;
}

describe('who may use it', function () {
    test('guests are sent to the login page', function () {
        $this->get(route('admin.settings.appearance.edit'))->assertRedirect(route('login'));
        $this->post(route('admin.settings.appearance.update'), [])->assertRedirect(route('login'));
    });

    test('a user without the superadmin role gets 403 and nothing is stored', function () {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

        $this->actingAs($user->fresh())->get(route('admin.settings.appearance.edit'))->assertForbidden();
        saveAppearance($this, ['logo' => appearanceFile('png', 'logo.png')], $user->fresh())->assertForbidden();

        expect(websiteFiles())->toBe([])->and(SiteSetting::query()->count())->toBe(0);
    });
});

describe('the page', function () {
    test('with nothing saved it shows empty fields and no images', function () {
        $this->actingAs($this->admin)->get(route('admin.settings.appearance.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/Appearance')
            ->where('appearance.logo_url', null)->where('appearance.favicon_url', null)->where('appearance.hero_image_url', null)
            ->where('appearance.hero_heading', '')->where('appearance.hero_description_my', ''));
    });

    test('it sends only public addresses with a version, never a storage key or a disk path', function () {
        $setting = savedAppearance();
        $version = $setting->fresh()->updated_at->getTimestamp();

        $this->actingAs($this->admin)->get(route('admin.settings.appearance.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('appearance.logo_url', Storage::disk(SiteSetting::DISK)->url('site/old-logo.png').'?v='.$version)
            ->where('appearance.hero_heading', 'Old heading')
            ->missing('appearance.logo_key')->missing('appearance.favicon_key')->missing('appearance.hero_image_key'));
    });
});

describe('saving', function () {
    test('an upload stores each file under site/ with a random name, saves the keys and who saved', function () {
        saveAppearance($this, [
            'logo' => appearanceFile('png', 'My Logo.png'), 'favicon' => appearanceFile('ico', 'favicon.ico'), 'hero_image' => appearanceFile('jpg', 'hero.jpg'),
        ])->assertRedirect(route('admin.settings.appearance.edit'))->assertSessionHasNoErrors();

        $setting = SiteSetting::query()->sole();

        expect($setting->logo_key)->toMatch('#^site/[0-9a-f-]{36}\.png$#')
            ->and($setting->favicon_key)->toMatch('#^site/[0-9a-f-]{36}\.ico$#')
            ->and($setting->hero_image_key)->toMatch('#^site/[0-9a-f-]{36}\.jpg$#')
            ->and($setting->updated_by)->toBe($this->admin->id)
            ->and(websiteFiles())->toHaveCount(3);

        foreach ([$setting->logo_key, $setting->favicon_key, $setting->hero_image_key] as $key) {
            Storage::disk(SiteSetting::DISK)->assertExists($key);
        }
    });

    test('a save that sends only some fields leaves the rest as they were', function () {
        $setting = savedAppearance();

        saveAppearance($this, ['hero_heading' => 'New heading'])->assertSessionHasNoErrors();

        $fresh = $setting->fresh();

        expect($fresh->hero_heading)->toBe('New heading')->and($fresh->hero_description)->toBe('Old description')
            ->and($fresh->logo_key)->toBe('site/old-logo.png')->and($fresh->hero_image_key)->toBe('site/old-hero.jpg')
            ->and(websiteFiles())->toHaveCount(3);
    });

    test('the hero text is trimmed and an empty text becomes empty, in both languages', function () {
        saveAppearance($this, ['hero_heading' => '  Visas  ', 'hero_heading_my' => 'ဗီဇာ', 'hero_description' => '   ', 'hero_description_my' => null]);

        $setting = SiteSetting::query()->sole();

        expect($setting->hero_heading)->toBe('Visas')->and($setting->hero_heading_my)->toBe('ဗီဇာ')
            ->and($setting->hero_description)->toBeNull()->and($setting->hero_description_my)->toBeNull();
    });

    test('the first save creates the row and later saves use the same one', function () {
        saveAppearance($this, ['hero_heading' => 'One']);
        saveAppearance($this, ['hero_heading' => 'Two']);

        expect(SiteSetting::query()->count())->toBe(1)->and(SiteSetting::query()->sole()->hero_heading)->toBe('Two');
    });

    test('a replacement deletes the old file only after the save is committed', function () {
        savedAppearance();
        $committed = false;
        Event::listen(TransactionCommitted::class, function () use (&$committed) {
            $committed = true;
        });
        $deletedBeforeCommit = [];
        $disk = Mockery::mock(Storage::disk(SiteSetting::DISK))->makePartial();
        $disk->shouldReceive('delete')->andReturnUsing(function ($key) use (&$committed, &$deletedBeforeCommit, $disk) {
            if (! $committed) {
                $deletedBeforeCommit[] = $key;
            }

            return $disk->getDriver()->delete($key) ?? true;
        });
        Storage::set(SiteSetting::DISK, $disk);

        saveAppearance($this, ['logo' => appearanceFile('webp', 'new.webp')])->assertSessionHasNoErrors();

        $setting = SiteSetting::query()->sole();

        expect($deletedBeforeCommit)->toBe([])
            ->and($setting->logo_key)->toEndWith('.webp')->and($setting->logo_key)->not->toBe('site/old-logo.png');
        Storage::disk(SiteSetting::DISK)->assertMissing('site/old-logo.png');
        Storage::disk(SiteSetting::DISK)->assertExists($setting->logo_key);
        Storage::disk(SiteSetting::DISK)->assertExists('site/old-favicon.png');
    });

    test('replacing the favicon and the hero image deletes their old files too', function () {
        savedAppearance();

        saveAppearance($this, ['favicon' => appearanceFile('png', 'f.png'), 'hero_image' => appearanceFile('png', 'h.png')]);

        Storage::disk(SiteSetting::DISK)->assertMissing('site/old-favicon.png');
        Storage::disk(SiteSetting::DISK)->assertMissing('site/old-hero.jpg');
        expect(websiteFiles())->toHaveCount(3);
    });

    test('the hero image can be removed, the logo and the favicon cannot', function () {
        savedAppearance();

        saveAppearance($this, ['remove_hero_image' => true, 'remove_logo' => true, 'remove_favicon' => true])->assertSessionHasNoErrors();

        $setting = SiteSetting::query()->sole();

        expect($setting->hero_image_key)->toBeNull()->and($setting->logo_key)->toBe('site/old-logo.png')->and($setting->favicon_key)->toBe('site/old-favicon.png');
        Storage::disk(SiteSetting::DISK)->assertMissing('site/old-hero.jpg');
        expect(websiteFiles())->toHaveCount(2);
    });

    test('a new hero image sent together with a removal wins and the old one is deleted', function () {
        savedAppearance();

        saveAppearance($this, ['remove_hero_image' => true, 'hero_image' => appearanceFile('jpg', 'h.jpg')]);

        $setting = SiteSetting::query()->sole();

        expect($setting->hero_image_key)->not->toBeNull()->and($setting->hero_image_key)->not->toBe('site/old-hero.jpg');
        Storage::disk(SiteSetting::DISK)->assertMissing('site/old-hero.jpg');
        Storage::disk(SiteSetting::DISK)->assertExists($setting->hero_image_key);
    });

    test('the cached settings and the image address update at once', function () {
        $setting = savedAppearance();
        $before = app(SiteSettings::class)->get()->logo_key;
        $this->travel(5)->seconds();

        saveAppearance($this, ['logo' => appearanceFile('png', 'new.png')]);

        $version = $setting->fresh()->updated_at->getTimestamp();

        expect($before)->toBe('site/old-logo.png')->and(app(SiteSettings::class)->get()->logo_key)->not->toBe($before);
        $this->actingAs($this->admin)->get(route('admin.settings.appearance.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('appearance.logo_url', fn ($url) => str_ends_with($url, '?v='.$version)));
    });
});

describe('when something goes wrong', function () {
    test('a refused file stores nothing, keeps the old values and shows the error', function () {
        $setting = savedAppearance();

        saveAppearance($this, ['logo' => appearanceFile('svg', 'logo.png'), 'hero_heading' => 'Ignored'])->assertSessionHasErrors('logo');

        expect(websiteFiles())->toHaveCount(3)->and($setting->fresh()->logo_key)->toBe('site/old-logo.png')->and($setting->fresh()->hero_heading)->toBe('Old heading');
    });

    test('a database failure keeps the old keys and text, removes the new files and leaves the old files', function () {
        $setting = savedAppearance();
        $this->withoutExceptionHandling();
        SiteSetting::saving(fn () => throw new RuntimeException('database is down'));

        expect(fn () => saveAppearance($this, ['logo' => appearanceFile('png', 'new.png'), 'hero_image' => appearanceFile('jpg', 'new.jpg'), 'hero_heading' => 'Lost']))
            ->toThrow(RuntimeException::class, 'database is down');

        $fresh = $setting->fresh();

        expect($fresh->logo_key)->toBe('site/old-logo.png')->and($fresh->hero_image_key)->toBe('site/old-hero.jpg')->and($fresh->hero_heading)->toBe('Old heading')
            ->and(websiteFiles())->toEqualCanonicalizing(['site/old-logo.png', 'site/old-favicon.png', 'site/old-hero.jpg']);
    });

    test('a file that cannot be stored removes the ones already stored and saves nothing', function () {
        $stored = 0;
        $disk = Mockery::mock(Storage::disk(SiteSetting::DISK))->makePartial();
        $disk->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use (&$stored, $disk) {
            return ++$stored === 2 ? false : $disk->getDriver()->write('site/'.$arguments[2], file_get_contents($arguments[1]->getRealPath())) ?? 'site/'.$arguments[2];
        });
        Storage::set(SiteSetting::DISK, $disk);

        saveAppearance($this, ['logo' => appearanceFile('png', 'l.png'), 'favicon' => appearanceFile('png', 'f.png')])->assertSessionHasErrors('favicon');

        expect(websiteFiles())->toBe([])->and(SiteSetting::query()->count())->toBe(0);
    });

    test('an old file that cannot be deleted is reported only: the save still succeeds', function () {
        Exceptions::fake();
        savedAppearance();
        $disk = Mockery::mock(Storage::disk(SiteSetting::DISK))->makePartial();
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::set(SiteSetting::DISK, $disk);

        saveAppearance($this, ['logo' => appearanceFile('png', 'new.png')])->assertSessionHasNoErrors();

        expect(SiteSetting::query()->sole()->logo_key)->not->toBe('site/old-logo.png');
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'site/old-logo.png'));
    });

    test('the text limits are enforced, in both languages', function () {
        saveAppearance($this, ['hero_heading' => str_repeat('a', 151), 'hero_description_my' => str_repeat('မ', 501)])
            ->assertSessionHasErrors(['hero_heading' => 'The hero heading can have at most 150 characters.', 'hero_description_my']);

        expect(SiteSetting::query()->count())->toBe(0);
    });
});
