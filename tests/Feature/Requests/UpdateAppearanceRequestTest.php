<?php

use App\Http\Requests\UpdateAppearanceRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The website image rules (Section 4.11): the logo PNG, JPG or WebP; the favicon PNG or ICO; the hero image JPG, PNG or WebP; at most
 * 2 MB; judged by the file's content, never its name; no SVG. See App\Concerns\ValidatesWebsiteImage.
 */
beforeEach(fn () => app()->setLocale('en'));

/** Real, tiny files of every kind. The bytes are what counts, never the name. */
function imageBytes(string $kind): string
{
    return match ($kind) {
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
        'jpg' => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
        'webp' => base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA=='),
        'ico' => "\x00\x00\x01\x00\x01\x00\x01\x01\x00\x00\x01\x00\x20\x00\x30\x00\x00\x00\x16\x00\x00\x00"
            ."\x28\x00\x00\x00\x01\x00\x00\x00\x02\x00\x00\x00\x01\x00\x20\x00\x00\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            ."\xff\x00\x00\xff\x00\x00\x00\x00",
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>',
        'html' => '<html><body><script>alert(1)</script></body></html>',
        'pdf' => "%PDF-1.4\n%%EOF\n",
        'text' => 'just some text',
    };
}

/** A real uploaded file whose content is `$kind`, saved under any name, optionally padded to be bigger. */
function imageUpload(string $kind, string $name, int $extraKilobytes = 0): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, imageBytes($kind).str_repeat("\0", $extraKilobytes * 1024));

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Runs the request's authorization and validation and gives back the validation errors.
 *
 * @param  array<string, UploadedFile>  $files
 * @return array<string, array<int, string>>
 */
function appearanceErrors(array $files, ?User $user = null): array
{
    $request = UpdateAppearanceRequest::create('/admin/settings/appearance', 'POST', [], [], $files);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => $user ?? User::factory()->create());

    try {
        $request->validateResolved();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('each allowed type is accepted for its image', function (string $field, string $kind, string $name) {
    expect(appearanceErrors([$field => imageUpload($kind, $name)]))->toBe([]);
})->with([
    'logo png' => ['logo', 'png', 'logo.png'],
    'logo jpg' => ['logo', 'jpg', 'logo.jpg'],
    'logo jpeg' => ['logo', 'jpg', 'logo.jpeg'],
    'logo webp' => ['logo', 'webp', 'logo.webp'],
    'favicon png' => ['favicon', 'png', 'favicon.png'],
    'favicon ico' => ['favicon', 'ico', 'favicon.ico'],
    'hero jpg' => ['hero_image', 'jpg', 'hero.jpg'],
    'hero png' => ['hero_image', 'png', 'hero.png'],
    'hero webp' => ['hero_image', 'webp', 'hero.webp'],
]);

test('every image is optional, so a save may change only some', function () {
    expect(appearanceErrors([]))->toBe([])
        ->and(appearanceErrors(['logo' => imageUpload('png', 'logo.png')]))->toBe([]);
});

test('an SVG is refused for every image, by its content, even when it is renamed to look like an allowed type', function (string $field, string $name) {
    $errors = appearanceErrors([$field => imageUpload('svg', $name)]);

    expect($errors)->toHaveKey($field)->and(array_keys($errors))->toBe([$field]);
})->with([
    'logo svg' => ['logo', 'logo.svg'],
    'logo svg renamed png' => ['logo', 'logo.png'],
    'logo svg renamed webp' => ['logo', 'logo.webp'],
    'favicon svg renamed ico' => ['favicon', 'favicon.ico'],
    'favicon svg renamed png' => ['favicon', 'favicon.png'],
    'hero svg renamed jpg' => ['hero_image', 'hero.jpg'],
]);

test('HTML, a PDF and plain text are refused, even renamed to look like an image', function (string $kind, string $field, string $name) {
    expect(appearanceErrors([$field => imageUpload($kind, $name)]))->toHaveKey($field);
})->with([
    'html as png' => ['html', 'logo', 'logo.png'],
    'html as jpg' => ['html', 'hero_image', 'hero.jpg'],
    'html as ico' => ['html', 'favicon', 'favicon.ico'],
    'pdf as png' => ['pdf', 'logo', 'logo.png'],
    'pdf as jpg' => ['pdf', 'hero_image', 'hero.jpg'],
    'text as webp' => ['text', 'logo', 'logo.webp'],
]);

test('a type allowed for another image is refused: an ICO is not a logo or a hero image, and a WebP is not a favicon', function (string $kind, string $field, string $name) {
    expect(appearanceErrors([$field => imageUpload($kind, $name)]))->toHaveKey($field);
})->with([
    'ico as logo' => ['ico', 'logo', 'logo.ico'],
    'ico as hero' => ['ico', 'hero_image', 'hero.ico'],
    'webp as favicon' => ['webp', 'favicon', 'favicon.webp'],
    'jpg as favicon' => ['jpg', 'favicon', 'favicon.jpg'],
]);

test('a real image with a wrong name is still accepted, because only the content counts', function () {
    expect(appearanceErrors(['logo' => imageUpload('png', 'logo.txt')]))->toBe([]);
});

test('a file over 2 MB is refused and one just under it is accepted', function () {
    expect(appearanceErrors(['logo' => imageUpload('png', 'logo.png', 2049)]))->toHaveKey('logo')
        ->and(appearanceErrors(['logo' => imageUpload('png', 'logo.png', 2040)]))->toBe([]);
});

test('the messages are clear in English', function () {
    expect(appearanceErrors(['logo' => imageUpload('svg', 'logo.png')])['logo'])->toBe(['The logo must be a PNG, JPG or WebP image.'])
        ->and(appearanceErrors(['favicon' => imageUpload('webp', 'favicon.png')])['favicon'])->toBe(['The favicon must be a PNG or ICO file.'])
        ->and(appearanceErrors(['hero_image' => imageUpload('pdf', 'hero.jpg')])['hero_image'])->toBe(['The hero image must be a JPG, PNG or WebP image.'])
        ->and(appearanceErrors(['logo' => imageUpload('png', 'logo.png', 2049)])['logo'])->toBe(['The image can be at most 2 MB.']);
});

test('the messages are shown in Burmese when the language is Burmese', function () {
    app()->setLocale('my');

    $errors = appearanceErrors(['logo' => imageUpload('svg', 'logo.png'), 'hero_image' => imageUpload('png', 'hero.png', 2049)]);

    expect($errors['logo'])->toBe(['လိုဂိုသည် PNG၊ JPG သို့မဟုတ် WebP ပုံ ဖြစ်ရပါမည်။'])
        ->and($errors['hero_image'])->toBe(['ပုံသည် ၂ MB ထက် မကြီးရပါ။']);
});

test('the storage key is random, keeps the folder and takes its extension from the content, never from the file name', function () {
    $request = UpdateAppearanceRequest::create('/admin/settings/appearance', 'POST', [], [], [
        'logo' => imageUpload('webp', '../../evil name.php'),
        'favicon' => imageUpload('ico', 'whatever.png'),
    ]);
    $request->setContainer(app());

    $logo = $request->newImageKey('logo');

    expect($logo)->toMatch('#^site/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.webp$#')
        ->and($request->newImageKey('favicon'))->toEndWith('.ico')
        ->and($request->newImageKey('logo'))->not->toBe($logo)
        ->and($request->newImageKey('hero_image'))->toBeNull();
});

test('only an admin who may manage the site settings can use the form, a guest cannot', function () {
    $request = UpdateAppearanceRequest::create('/admin/settings/appearance', 'POST');
    $request->setUserResolver(fn () => User::factory()->create());
    expect($request->authorize())->toBeTrue();

    $guest = UpdateAppearanceRequest::create('/admin/settings/appearance', 'POST');
    $guest->setUserResolver(fn () => null);
    expect($guest->authorize())->toBeFalse();
});
