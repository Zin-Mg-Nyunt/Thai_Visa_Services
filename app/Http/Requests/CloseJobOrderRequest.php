<?php

namespace App\Http\Requests;

use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CloseJobOrderRequest extends FormRequest
{
    /**
     * Only an open order can be closed: a closed order is final.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('close', $this->route('jobOrder')) ?? false;
    }

    /**
     * An order closes as done or cancelled, never as open.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([JobOrderStatus::Done->value, JobOrderStatus::Cancelled->value])],
            // Sent by the edit page: a closed order's edit page is refused, so the admin goes back to the list.
            'from' => ['nullable', Rule::in(['edit'])],
            // What the admin chose for every open application of a person who is not deleted (Hired, Rejected or Cancelled).
            'resolutions' => ['nullable', 'array', 'max:500'],
            'resolutions.*.worker_id' => ['required', 'integer', 'distinct'],
            'resolutions.*.status' => ['required', Rule::in([WorkerCaseStatus::Hired->value, WorkerCaseStatus::Rejected->value, WorkerCaseStatus::Cancelled->value])],
            'confirm_hired' => ['nullable', 'boolean'],
            // The state the admin's screen was based on. The server compares it with the locked, current state.
            'expected' => ['nullable', 'array'],
            'expected.open' => ['nullable', 'array', 'max:500'],
            'expected.open.*.worker_id' => ['required', 'integer', 'distinct'],
            'expected.open.*.status' => ['required', Rule::in([WorkerCaseStatus::Applied->value, WorkerCaseStatus::Interview->value])],
            'expected.deleted_open' => ['nullable', 'array', 'max:500'],
            'expected.deleted_open.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Hired always needs the admin's explicit confirmation, here as everywhere else.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hired = false;

                foreach ($this->array('resolutions') as $resolution) {
                    if (is_array($resolution) && ($resolution['status'] ?? null) === WorkerCaseStatus::Hired->value) {
                        $hired = true;

                        break;
                    }
                }

                if ($hired && ! $this->boolean('confirm_hired')) {
                    $validator->errors()->add('confirm_hired', __('Confirm Hired first: the Job Search service becomes Complete, the person cannot be assigned to another job order while Hired, and Hired can be undone only by cancelling the Hired application later.'));
                }
            },
        ];
    }

    /**
     * @return array<int, WorkerCaseStatus> application id => the status chosen for it
     */
    public function resolutions(): array
    {
        return $this->statusMap('resolutions');
    }

    /**
     * @return array<int, WorkerCaseStatus> application id => its status on the admin's screen
     */
    public function expectedOpen(): array
    {
        return $this->statusMap('expected.open');
    }

    /**
     * @return array<int, int>
     */
    public function expectedDeletedOpen(): array
    {
        return array_map(intval(...), $this->array('expected.deleted_open'));
    }

    /**
     * @return array<int, WorkerCaseStatus>
     */
    private function statusMap(string $key): array
    {
        $map = [];

        foreach ($this->array($key) as $item) {
            $map[(int) $item['worker_id']] = WorkerCaseStatus::from($item['status']);
        }

        return $map;
    }
}
