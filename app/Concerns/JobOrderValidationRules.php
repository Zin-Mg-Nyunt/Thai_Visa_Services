<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;

trait JobOrderValidationRules
{
    /**
     * The largest request a count column can hold. This is only the size of the column: manpower is the company's
     * request, not a limit, so nothing compares it with the male/female counts or with the workers added.
     */
    private const COUNT_MAX = 65535;

    /**
     * The rules for the fields an admin enters on a job order. The status and closing time are never entered: an order
     * is closed only by JobOrder::close().
     *
     * @return array<string, array<int, ValidationRule|ConditionalRules|string>>
     */
    protected function jobOrderRules(): array
    {
        $count = ['required', 'integer', 'min:0', 'max:'.self::COUNT_MAX];

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'manpower' => $count,
            'male_count' => $count,
            'female_count' => $count,
            'interview_date' => ['nullable', 'date'],
            // Not before the interview, the same day is fine. Only compared when an interview date is given.
            'start_date' => ['nullable', 'date', Rule::when($this->filled('interview_date'), ['after_or_equal:interview_date'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function jobOrderMessages(): array
    {
        return [
            'start_date.after_or_equal' => __('The start-working date cannot be before the interview date.'),
        ];
    }
}
