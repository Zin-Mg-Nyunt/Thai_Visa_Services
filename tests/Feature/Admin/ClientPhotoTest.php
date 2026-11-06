<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(Client::PHOTO_DISK);
});

/**
 * A real file on disk, so the server reads the type from the content as it does in production
 * (a fake upload would take its type from the file name).
 */
function uploadWithContent(string $content, string $name = 'photo.jpg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function jpegUpload(string $name = 'photo.jpg', int $extraKilobytes = 0): UploadedFile
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

    return uploadWithContent($jpeg.str_repeat("\0", $extraKilobytes * 1024), $name);
}

function clientWithPhoto(): Client
{
    $client = Client::factory()->create(['phone' => '+66812345678']);
    $path = Client::PHOTO_DIRECTORY.'/existing.jpg';
    Storage::disk(Client::PHOTO_DISK)->put($path, 'jpeg-bytes');
    $client->forceFill(['photo_path' => $path])->save();

    return $client;
}

describe('upload', function () {
    test('a superadmin saves a JPG on the private disk', function () {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.photo.update', $client), ['photo' => jpegUpload()])
            ->assertRedirect(route('admin.clients.show', $client))
            ->assertSessionHasNoErrors();

        $client->refresh();

        expect($client->photo_path)->toStartWith(Client::PHOTO_DIRECTORY.'/')->toEndWith('.jpg');
        Storage::disk(Client::PHOTO_DISK)->assertExists($client->photo_path);
        Storage::disk('public')->assertDirectoryEmpty(Client::PHOTO_DIRECTORY);
    });

    test('a new photo replaces the old file', function () {
        $client = clientWithPhoto();
        $oldPath = $client->photo_path;

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.photo.update', $client), ['photo' => jpegUpload()]);

        Storage::disk(Client::PHOTO_DISK)->assertMissing($oldPath);
        Storage::disk(Client::PHOTO_DISK)->assertExists($client->fresh()->photo_path);
        expect(Storage::disk(Client::PHOTO_DISK)->allFiles(Client::PHOTO_DIRECTORY))->toHaveCount(1);
    });

    test('only a JPG up to 2 MB is accepted', function (callable $file) {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.photo.update', $client), ['photo' => $file()])
            ->assertSessionHasErrors('photo');

        expect($client->fresh()->photo_path)->toBeNull();
    })->with([
        'a PNG renamed to .jpg' => [fn () => uploadWithContent(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHjwV7gAAAABJRU5ErkJggg=='))],
        'a text file named .jpg' => [fn () => uploadWithContent('not an image')],
        'a PDF' => [fn () => uploadWithContent("%PDF-1.4\n%%EOF", 'photo.pdf')],
        'a JPG over 2 MB' => [fn () => jpegUpload('big.jpg', 2100)],
    ]);

    test('a JPG just under 2 MB is accepted', function () {
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.photo.update', $client), ['photo' => jpegUpload('ok.jpg', 2000)])
            ->assertSessionHasNoErrors();

        expect($client->fresh()->photo_path)->not->toBeNull();
    });

    test('a photo is required', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.photo.update', Client::factory()->create()))
            ->assertSessionHasErrors('photo');
    });

    test('only a superadmin can change a photo', function () {
        $client = Client::factory()->create();

        $this->post(route('admin.clients.photo.update', $client), ['photo' => jpegUpload()])
            ->assertRedirect(route('login'));

        $staff = User::factory()->create();
        DB::table('users')->where('id', $staff->id)->update(['role' => 'staff']);
        $this->actingAs($staff->fresh())
            ->post(route('admin.clients.photo.update', $client), ['photo' => jpegUpload()])
            ->assertForbidden();

        expect($client->fresh()->photo_path)->toBeNull();
    });
});

describe('adding a client with a photo', function () {
    beforeEach(function () {
        $this->input = fn (array $overrides = []) => [
            'name' => 'Aung Min', 'phone' => '081 234 5678', 'birthday' => '1995-03-12', 'gender' => 'male', ...$overrides,
        ];
    });

    test('saves the photo on the private disk with the new client', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), ($this->input)(['photo' => jpegUpload()]))
            ->assertSessionHasNoErrors();

        $client = Client::query()->sole();

        expect($client->photo_path)->toStartWith(Client::PHOTO_DIRECTORY.'/')->toEndWith('.jpg');
        Storage::disk(Client::PHOTO_DISK)->assertExists($client->photo_path);
    });

    test('the photo is optional', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), ($this->input)())
            ->assertSessionHasNoErrors();

        expect(Client::query()->sole()->photo_path)->toBeNull();
    });

    test('a photo that is not a JPG or too large adds no client and no file', function (Closure $photo) {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.store'), ($this->input)(['photo' => $photo()]))
            ->assertSessionHasErrors('photo');

        expect(Client::count())->toBe(0);
        Storage::disk(Client::PHOTO_DISK)->assertDirectoryEmpty(Client::PHOTO_DIRECTORY);
    })->with([
        'a PNG renamed to .jpg' => [fn () => uploadWithContent(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHjwV7gAAAABJRU5ErkJggg=='))],
        'over 2 MB' => [fn () => jpegUpload(extraKilobytes: Client::PHOTO_MAX_KILOBYTES + 1)],
    ]);
});

describe('remove and delete', function () {
    test('a superadmin can remove the photo', function () {
        $client = clientWithPhoto();
        $path = $client->photo_path;

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.clients.photo.destroy', $client))
            ->assertRedirect(route('admin.clients.show', $client));

        expect($client->fresh()->photo_path)->toBeNull();
        Storage::disk(Client::PHOTO_DISK)->assertMissing($path);
    });

    test('only a superadmin can remove the photo', function () {
        $client = clientWithPhoto();

        $this->delete(route('admin.clients.photo.destroy', $client))->assertRedirect(route('login'));

        Storage::disk(Client::PHOTO_DISK)->assertExists($client->photo_path);
    });

    test('deleting a client erases the photo file', function () {
        $client = clientWithPhoto();
        $path = $client->photo_path;

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.clients.destroy', $client), ['confirmation' => '+66812345678'])
            ->assertRedirect(route('admin.clients.index'));

        expect(Client::find($client->id))->toBeNull();
        Storage::disk(Client::PHOTO_DISK)->assertMissing($path);
        expect(Client::withTrashed()->find($client->id)->photo_path)->toBeNull();
    });
});

describe('viewing', function () {
    test('the detail page gets a signed link only when there is a photo', function () {
        $admin = User::factory()->create();
        $without = Client::factory()->create();
        $with = clientWithPhoto();

        $this->actingAs($admin)->get(route('admin.clients.show', $without))
            ->assertInertia(fn (Assert $page) => $page->where('photoUrl', null));

        $this->actingAs($admin)->get(route('admin.clients.show', $with))
            ->assertInertia(fn (Assert $page) => $page->where('photoUrl', fn ($url) => str_contains($url, 'signature=')));
    });

    test('a superadmin opens the photo with the signed link', function () {
        $client = clientWithPhoto();

        $this->actingAs(User::factory()->create())
            ->get($client->photoUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    });

    test('the browser is told never to keep a copy of the photo', function () {
        $client = clientWithPhoto();

        $cacheControl = $this->actingAs(User::factory()->create())
            ->get($client->photoUrl())
            ->headers->get('Cache-Control');

        expect($cacheControl)->toContain('no-store')
            ->and($cacheControl)->toContain('private')
            ->and($cacheControl)->not->toContain('max-age');
    });

    test('a signed-in client opens only their own photo', function () {
        $client = clientWithPhoto();
        $other = Client::factory()->create(['phone' => '+66899991111']);
        Storage::disk(Client::PHOTO_DISK)->put('client-photos/other.jpg', 'x');
        $other->forceFill(['photo_path' => 'client-photos/other.jpg'])->save();

        $this->post(route('track.verify', $client->unique_token), ['pin' => '5678']);

        $this->get($client->photoUrl())->assertOk();
        $this->get($other->photoUrl())->assertForbidden();
    });

    test('the profile page carries the client\'s own signed photo link', function () {
        $client = clientWithPhoto();
        $this->post(route('track.verify', $client->unique_token), ['pin' => '5678']);

        $this->get(route('client.profile', $client->unique_token))
            ->assertInertia(fn (Assert $page) => $page->where('profile.data.photo_url', fn ($url) => str_contains($url, 'signature=')));
    });

    test('a signed link opened by a visitor with no access shows nothing', function () {
        $client = clientWithPhoto();

        $this->get($client->photoUrl())->assertForbidden();
    });

    test('an unsigned, altered or expired link is refused', function () {
        $client = clientWithPhoto();
        $this->actingAs(User::factory()->create());

        $this->get(route('client-photos.show', $client))->assertForbidden();
        $this->get($client->photoUrl().'0')->assertForbidden();

        $url = $client->photoUrl();
        $this->travel(2)->hours();
        $this->get($url)->assertForbidden();
    });

    test('a client without a photo, or a deleted client, is not found', function () {
        $admin = User::factory()->create();
        $client = Client::factory()->create();
        $withPhoto = clientWithPhoto();

        $withoutUrl = URL::temporarySignedRoute('client-photos.show', now()->addHour(), ['client' => $client->id]);
        $this->actingAs($admin)->get($withoutUrl)->assertNotFound();

        $url = $withPhoto->photoUrl();
        $withPhoto->delete();
        $this->actingAs($admin)->get($url)->assertNotFound();
    });

    test('the photo link does not expose the file path', function () {
        $client = clientWithPhoto();

        expect($client->photoUrl())->not->toContain('client-photos/existing')->not->toContain($client->unique_token);
    });
});
