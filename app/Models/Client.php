<?php

namespace App\Models;

use App\Enums\Gender;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property int $id
 * @property string $unique_token
 * @property string $name
 * @property string $phone
 * @property Carbon $birthday
 * @property Gender $gender
 * @property string|null $remarks
 * @property string|null $photo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'phone', 'birthday', 'gender', 'remarks'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    public const PHOTO_DISK = 'private';

    public const PHOTO_DIRECTORY = 'client-photos';

    public const PHOTO_MAX_KILOBYTES = 2048;

    /**
     * Give every new client a long random tracking token, and erase the photo file when a client is deleted.
     */
    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->unique_token ??= Str::random(48);
        });

        static::deleting(function (Client $client): void {
            $client->erasePhoto();
        });
    }

    /**
     * A temporary signed link to the photo, or null when there is none. The photo route also
     * checks who is asking, so the link alone is not enough to see the photo.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null) {
            return null;
        }

        return URL::temporarySignedRoute('client-photos.show', now()->addHour(), ['client' => $this->id]);
    }

    /**
     * Save a new profile photo on the private disk and remove the one it replaces. If the record cannot be
     * saved, the new file is removed again, so no stray photo is left on the disk.
     */
    public function replacePhoto(UploadedFile $photo): void
    {
        $oldPath = $this->photo_path;
        $newPath = $photo->storeAs(self::PHOTO_DIRECTORY, Str::uuid().'.jpg', self::PHOTO_DISK);

        try {
            $this->forceFill(['photo_path' => $newPath])->save();
        } catch (Throwable $exception) {
            Storage::disk(self::PHOTO_DISK)->delete($newPath);

            throw $exception;
        }

        if ($oldPath !== null) {
            Storage::disk(self::PHOTO_DISK)->delete($oldPath);
        }
    }

    /**
     * Delete the photo file and forget its path.
     */
    public function erasePhoto(): void
    {
        if ($this->photo_path === null) {
            return;
        }

        Storage::disk(self::PHOTO_DISK)->delete($this->photo_path);

        $this->forceFill(['photo_path' => null])->saveQuietly();
    }

    /**
     * @return HasMany<ClientService, $this>
     */
    public function clientServices(): HasMany
    {
        return $this->hasMany(ClientService::class);
    }

    /**
     * Wrong PINs typed on this client's tracking link, newest first.
     *
     * @return HasMany<ClientPinAttempt, $this>
     */
    public function pinAttempts(): HasMany
    {
        return $this->hasMany(ClientPinAttempt::class)->latest()->latest('id');
    }

    /**
     * The link the client opens (or scans as a QR code) to reach the PIN page.
     */
    public function trackingUrl(): string
    {
        return route('track.show', ['client' => $this->unique_token]);
    }

    /**
     * The 4-digit PIN a client types on the tracking page: the last 4 digits of their phone number.
     */
    public function pin(): string
    {
        return substr(preg_replace('/\D/', '', $this->phone) ?? '', -4);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'gender' => Gender::class,
        ];
    }

    /**
     * Store the phone number in international format.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : static::normalisePhone($value));
    }

    /**
     * Strip formatting from a phone number and convert it to international format.
     * A leading 00 becomes +, and a single leading 0 is read as a Thai number (+66).
     * Anything else is returned without formatting and left to validation.
     */
    public static function normalisePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';

        return match (true) {
            str_starts_with($phone, '+') => $phone,
            str_starts_with($phone, '00') => '+'.substr($phone, 2),
            str_starts_with($phone, '0') => '+66'.substr($phone, 1),
            default => $phone,
        };
    }
}
