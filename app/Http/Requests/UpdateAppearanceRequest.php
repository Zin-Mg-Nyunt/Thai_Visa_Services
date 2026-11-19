<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesWebsiteImage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The website appearance form (Section 4.11): the logo (`logo`), the favicon (`favicon`), the hero image (`hero_image`), the
 * removal of the hero image (`remove_hero_image`) and the hero heading and description in both languages. Everything is optional,
 * so a save changes only what it sends; the logo and the favicon can only be replaced, never removed.
 */
class UpdateAppearanceRequest extends FormRequest
{
    use ValidatesWebsiteImage;

    /** The longest hero heading and description, in characters. */
    public const HEADING_MAX = 150;

    public const DESCRIPTION_MAX = 500;

    /** The hero text columns, English and Burmese. */
    public const TEXT_FIELDS = ['hero_heading', 'hero_heading_my', 'hero_description', 'hero_description_my'];

    /**
     * Only an admin who may manage the website settings; anyone else gets 403.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-site-settings') ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...$this->imageRules(),
            'remove_hero_image' => ['sometimes', 'boolean'],
            'hero_heading' => ['nullable', 'string', 'max:'.self::HEADING_MAX],
            'hero_heading_my' => ['nullable', 'string', 'max:'.self::HEADING_MAX],
            'hero_description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'hero_description_my' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->imageMessages(),
            'hero_heading.max' => __('The hero heading can have at most 150 characters.'),
            'hero_heading_my.max' => __('The hero heading can have at most 150 characters.'),
            'hero_description.max' => __('The hero description can have at most 500 characters.'),
            'hero_description_my.max' => __('The hero description can have at most 500 characters.'),
        ];
    }
}
