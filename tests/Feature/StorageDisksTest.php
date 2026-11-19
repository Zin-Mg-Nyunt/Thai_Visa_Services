<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Storage;

test('private and public asset disks use local storage by default', function () {
    expect(config('filesystems.disks.private.driver'))->toBe('local')
        ->and(config('filesystems.disks.public_assets.driver'))->toBe('local')
        ->and(config('filesystems.disks.private.visibility'))->toBe('private')
        ->and(config('filesystems.disks.public_assets.visibility'))->toBe('public');
});

test('private files are not stored inside the web-served public folder', function () {
    expect(config('filesystems.disks.private.root'))
        ->not->toStartWith(public_path())
        ->not->toStartWith(storage_path('app/public'));
});

test('a private file has no url of its own: only the app hands it out, after its checks', function () {
    $disk = Storage::disk('private');
    $path = 'testing/passport-'.str()->random(8).'.pdf';
    $disk->put($path, 'pdf');

    try {
        expect(fn () => $disk->temporaryUrl($path, now()->addMinutes(5)))->toThrow(RuntimeException::class);

        $this->get(rtrim(config('filesystems.disks.private.url'), '/').'/'.$path)->assertNotFound();
        $this->get('/storage/'.$path)->assertNotFound();
    } finally {
        $disk->delete($path);
    }
});

test('public asset urls are built from the configured public base url', function () {
    config(['filesystems.disks.public_assets.url' => 'https://assets.example.test']);

    expect(Storage::build(config('filesystems.disks.public_assets'))->url('site/logo.png'))
        ->toBe('https://assets.example.test/site/logo.png');
});

/**
 * The disks config as it is when the file is loaded with the given environment values.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function filesystemsConfigWith(array $environment): array
{
    $repository = Env::getRepository();
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = $repository->get($name);
        $value === null ? $repository->clear($name) : $repository->set($name, $value);
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        foreach ($previous as $name => $value) {
            $value === null ? $repository->clear($name) : $repository->set($name, $value);
        }
    }
}

test('an empty PUBLIC_ASSETS_URL falls back to the app address plus /storage, like an unset one', function (?string $value) {
    $url = filesystemsConfigWith(['PUBLIC_ASSETS_URL' => $value, 'APP_URL' => 'http://localhost:8000/'])['disks']['public_assets']['url'];

    expect($url)->toBe('http://localhost:8000/storage');
})->with(['empty' => [''], 'unset' => [null]]);

test('a PUBLIC_ASSETS_URL that is set wins, as in production with the Spaces bucket', function () {
    $url = filesystemsConfigWith(['PUBLIC_ASSETS_URL' => 'https://assets.example.test', 'APP_URL' => 'http://localhost:8000'])['disks']['public_assets']['url'];

    expect($url)->toBe('https://assets.example.test');
});
