<?php

namespace App\Models;

use App\Enums\Gender;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $unique_token
 * @property string $name
 * @property string $phone
 * @property Carbon $birthday
 * @property Gender $gender
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'phone', 'birthday', 'gender', 'remarks'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Give every new client a long random tracking token.
     */
    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->unique_token ??= Str::random(48);
        });
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
