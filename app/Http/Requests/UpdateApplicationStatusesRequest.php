<?php

namespace App\Http\Requests;

use App\Enums\WorkerCaseStatus;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * New statuses for one or several applications of an open order: changes = [{worker_id, status}, ...]. Hired always needs the
 * admin's explicit confirmation (confirm_hired), for one application and for a bulk update alike.
 */
class UpdateApplicationStatusesRequest extends FormRequest
{
    /**
     * A closed order is frozen: its application statuses do not change.
     */
    public function authorize(): bool
    {
        /** @var JobOrder $jobOrder */
        $jobOrder = $this->route('jobOrder');

        return $this->user()?->can('changeStatus', [JobOrderWorker::class, $jobOrder]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:200'],
            'changes.*.worker_id' => ['required', 'integer', 'distinct'],
            'changes.*.status' => ['required', Rule::enum(WorkerCaseStatus::class)],
            'confirm_hired' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hired = false;

                foreach ($this->array('changes') as $change) {
                    if (is_array($change) && ($change['status'] ?? null) === WorkerCaseStatus::Hired->value) {
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
     * @return array<int, WorkerCaseStatus> application id => new status
     */
    public function statusChanges(): array
    {
        $changes = [];

        foreach ($this->validated('changes') as $change) {
            $changes[(int) $change['worker_id']] = WorkerCaseStatus::from($change['status']);
        }

        return $changes;
    }
}
