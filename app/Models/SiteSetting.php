<?php

namespace App\Models;

use App\Actions\Site\SiteSettings;
use Database\Factories\SiteSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The public website's settings: the agency name (one name for every language, shown beside the logo everywhere), agency texts in English and Burmese, contact details, the three image keys, the numbers
 * approved for display and the publish switch. There is exactly one row, with id 1; read it through
 * {@see SiteSettings}, which caches it.
 *
 * The three image keys, `updated_by` and the id are not mass assignable: only code that sets them explicitly (after the
 * file checks) can change them. Saving or deleting the row clears the cached copy once the surrounding transaction has
 * committed, never after a rollback.
 *
 * @property int $id
 * @property string|null $agency_name
 * @property string|null $hero_heading
 * @property string|null $hero_heading_my
 * @property string|null $hero_description
 * @property string|null $hero_description_my
 * @property string|null $overview
 * @property string|null $overview_my
 * @property string|null $experience
 * @property string|null $experience_my
 * @property string|null $mission
 * @property string|null $mission_my
 * @property string|null $how_we_help
 * @property string|null $how_we_help_my
 * @property string|null $logo_key
 * @property string|null $favicon_key
 * @property string|null $hero_image_key
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $address_my
 * @property string|null $messaging_link
 * @property array<string, mixed>|null $social_links
 * @property array<int, string>|null $approved_statistics
 * @property bool $is_published
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'agency_name',
    'hero_heading', 'hero_heading_my',
    'hero_description', 'hero_description_my',
    'overview', 'overview_my',
    'experience', 'experience_my',
    'mission', 'mission_my',
    'how_we_help', 'how_we_help_my',
    'phone', 'email',
    'address', 'address_my',
    'messaging_link', 'social_links',
    'approved_statistics',
    'is_published',
])]
class SiteSetting extends Model
{
    /** @use HasFactory<SiteSettingFactory> */
    use HasFactory;

    /** The only row's id. */
    public const ID = 1;

    /** The disk of the three website images: public, so a visitor's browser loads them from their address. */
    public const DISK = 'public_assets';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_published' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'approved_statistics' => 'array',
            'is_published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => SiteSettings::forgetAfterCommit());
        static::deleted(fn () => SiteSettings::forgetAfterCommit());
    }

    /**
     * Create the one row if it does not exist yet, so a save can lock it. The first admin to save creates it; one who saves at the
     * same moment finds it already there.
     */
    public static function ensureExists(): void
    {
        if (self::query()->whereKey(self::ID)->exists()) {
            return;
        }

        try {
            self::query()->forceCreate(['id' => self::ID]);
        } catch (UniqueConstraintViolationException) {
            // Another admin created it a moment ago.
        }
    }

    /**
     * A text in the visitor's language, or in the other language when that one is empty, or null when both are empty.
     * `$field` is the English column (for example `mission`); its Burmese column is `{$field}_my`.
     */
    public function text(string $field, ?string $locale = null): ?string
    {
        $english = $this->getAttribute($field);
        $burmese = $this->getAttribute($field.'_my');

        $values = ($locale ?? app()->getLocale()) === 'my' ? [$burmese, $english] : [$english, $burmese];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The public address of one of the three images, or null when there is none: the disk's address plus the key, with the time of
     * the last save so a browser fetches a replaced image at once. Only this address ever leaves the server, never the key or a
     * path on the disk.
     */
    public function imageUrl(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $url = Storage::disk(self::DISK)->url($key);

        return $this->updated_at === null ? $url : $url.'?v='.$this->updated_at->getTimestamp();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
