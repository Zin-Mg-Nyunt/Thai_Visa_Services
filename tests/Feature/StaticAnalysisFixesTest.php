<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Behaviour the static-analysis fixes rely on or changed on purpose.

test('only the superadmin role counts as superadmin, and an unknown stored role does not', function () {
    $user = User::factory()->create();
    expect($user->isSuperadmin())->toBeTrue()
        ->and(User::factory()->make()->isSuperadmin())->toBeTrue();

    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    expect(User::find($user->id)->isSuperadmin())->toBeFalse();
});

test('a profile photo that cannot be stored is not saved, and the old photo stays', function () {
    Storage::fake(Client::PHOTO_DISK);
    $person = Client::factory()->create();
    $old = Client::PHOTO_DIRECTORY.'/old.jpg';
    Storage::disk(Client::PHOTO_DISK)->put($old, 'jpeg');
    $person->forceFill(['photo_path' => $old])->save();

    $disk = Mockery::mock(Storage::disk(Client::PHOTO_DISK))->makePartial();
    $disk->shouldReceive('putFileAs')->andReturn(false);
    Storage::set(Client::PHOTO_DISK, $disk);

    $file = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($file, 'jpeg');

    expect(fn () => $person->replacePhoto(new UploadedFile($file, 'photo.jpg', null, null, true)))
        ->toThrow(RuntimeException::class, 'could not be stored');

    expect($person->fresh()->photo_path)->toBe($old)
        ->and(Storage::disk(Client::PHOTO_DISK)->exists($old))->toBeTrue();
});
