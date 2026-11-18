<?php

namespace App\Http\Requests;

use App\Models\JobOrderWorker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling one Hired application. The admin must confirm explicitly: the Job Search service goes back to In Progress, the
 * person can be assigned to another job order, the order's Money falls and the Kept Amount becomes Unassigned.
 */
class CancelHiredApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var JobOrderWorker $worker */
        $worker = $this->route('worker');

        return $this->user()?->can('cancelHired', $worker) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['confirm_cancel_hired' => ['accepted']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_cancel_hired.accepted' => __('Confirm first: the application becomes Cancelled, the Job Search service goes back to In Progress, the job order\'s money decreases, and the kept amount becomes Unassigned until another job order is assigned.'),
        ];
    }
}
