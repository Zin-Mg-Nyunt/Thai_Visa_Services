<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publishes or unpublishes the public website (Section 4.10): `published` is true to publish and false to unpublish. It is its own
 * action, separate from saving the content, so that a change that decides what the public sees is never a side effect of Save.
 */
class PublishWebsiteRequest extends FormRequest
{
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
        return ['published' => ['required', 'boolean']];
    }
}
