<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesWebsiteImage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The website appearance form (Section 4.11). This step holds its image rules: the logo (`logo`), the favicon (`favicon`) and the
 * hero image (`hero_image`), each optional so a save can change only some. The hero text and the removal of the hero image are
 * added with the screen.
 */
class UpdateAppearanceRequest extends FormRequest
{
    use ValidatesWebsiteImage;

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
        return $this->imageRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->imageMessages();
    }
}
