<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Website content form (Section 4.10): the agency name (one name for every language), the About texts, the contact details, the social links, which statistics
 * may be shown. Every text has an English and a Burmese version. Everything is optional, so a save changes
 * only what it sends; an empty text is stored as empty and hidden on the website.
 *
 * Publishing is a separate action ({@see PublishWebsiteRequest}), so saving the content never changes whether the website is published.
 *
 * Every link must be a full http or https address, so a link can never be a script or another kind of address.
 */
class UpdateWebsiteContentRequest extends FormRequest
{
    /** The longest agency name, address and About text, in characters. */
    public const NAME_MAX = 120;

    public const ADDRESS_MAX = 500;

    public const ABOUT_MAX = 1000;

    public const LINK_MAX = 2048;

    /** The texts with an English and a Burmese version, and the longest each may be. The agency name is one name, not listed here. */
    public const TEXT_LIMITS = [
        'overview' => self::ABOUT_MAX,
        'experience' => self::ABOUT_MAX,
        'mission' => self::ABOUT_MAX,
        'how_we_help' => self::ABOUT_MAX,
        'address' => self::ADDRESS_MAX,
    ];

    /** The social media accounts that can be linked. */
    public const SOCIAL_PLATFORMS = ['facebook', 'instagram', 'tiktok', 'youtube'];

    /** The public numbers the admin can approve for the landing page. */
    public const STATISTICS = ['clients_served', 'services_provided'];

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
        $rules = [];

        foreach (self::TEXT_LIMITS as $field => $max) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
            $rules[$field.'_my'] = ['nullable', 'string', 'max:'.$max];
        }

        return [
            'agency_name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
            ...$rules,
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+\-().\s]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'messaging_link' => ['nullable', 'string', 'url:http,https', 'max:'.self::LINK_MAX],
            'social_links' => ['sometimes', 'nullable', 'array:'.implode(',', self::SOCIAL_PLATFORMS)],
            'social_links.*' => ['nullable', 'string', 'url:http,https', 'max:'.self::LINK_MAX],
            'approved_statistics' => ['sometimes', 'nullable', 'array'],
            'approved_statistics.*' => ['string', Rule::in(self::STATISTICS)],
        ];
    }

    /**
     * The words used for the fields in the messages, in the selected language.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'agency_name' => __('agency name'),
            'phone' => __('phone number'),
            'email' => __('email address'),
            'messaging_link' => __('messaging link'),
            'social_links.*' => __('social media link'),
            'approved_statistics.*' => __('statistic'),
        ];

        foreach (['overview' => 'overview', 'experience' => 'experience text', 'mission' => 'mission', 'how_we_help' => 'how-we-help text', 'address' => 'address'] as $field => $label) {
            $attributes[$field] = __($label).' ('.__('English').')';
            $attributes[$field.'_my'] = __($label).' ('.__('Burmese').')';
        }

        // Brand names are the same in every language.
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'] as $platform => $name) {
            $attributes["social_links.{$platform}"] = $name;
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'agency_name.max' => __('This text can have at most :max characters.'),
            'phone.regex' => __('Enter a phone number with digits, spaces and + - ( ) only.'),
            'email.email' => __('Enter a valid email address.'),
            'messaging_link.url' => __('Enter a full web address that starts with http:// or https://.'),
            'social_links.*.url' => __('Enter a full web address that starts with http:// or https://.'),
            'approved_statistics.*.in' => __('Choose only the statistics that are listed.'),
        ];

        foreach (array_keys(self::TEXT_LIMITS) as $field) {
            $messages[$field.'.max'] = __('This text can have at most :max characters.');
            $messages[$field.'_my.max'] = __('This text can have at most :max characters.');
        }

        return $messages;
    }
}
