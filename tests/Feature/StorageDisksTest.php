<?php

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
