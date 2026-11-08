<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The date range of the Business dashboard. The dashboard is a page of figures, so a bad range never sends the admin to
 * another page: a bad preset falls back to this month, and a bad custom range stays chosen, keeps what was typed and
 * shows its error on the dashboard, with this month's figures until it is fixed.
 */
class DashboardRangeRequest extends FormRequest
{
    public const RANGES = ['this_month', 'last_month', 'this_year', 'custom'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewDashboard') ?? false;
    }

    /**
     * The dates are checked only for a custom range; a preset ignores them.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d'],
            'to' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => __('The end date cannot be before the start date.'),
        ];
    }

    /**
     * Keep the errors for the page instead of redirecting back; dates() decides what to show.
     */
    protected function failedValidation(Validator $validator): void
    {
        //
    }

    /**
     * The range to show, as dates in the agency's time zone, with the custom dates as typed and their errors.
     *
     * @return array{key: string, from: CarbonImmutable, to: CarbonImmutable, input: array{from: string, to: string}, errors: array<string, string>}
     */
    public function dates(): array
    {
        $timezone = config('app.display_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $key = in_array($this->query('range'), self::RANGES, true) ? $this->query('range') : 'this_month';

        $errors = $key === 'custom'
            ? array_map(fn (array $messages) => $messages[0], $this->validator->errors()->toArray())
            : [];

        [$from, $to] = match (true) {
            $key === 'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            $key === 'this_year' => [$today->startOfYear(), $today],
            $key === 'custom' && $errors === [] => [
                CarbonImmutable::parse($this->query('from'), $timezone)->startOfDay(),
                CarbonImmutable::parse($this->query('to'), $timezone)->startOfDay(),
            ],
            default => [$today->startOfMonth(), $today],
        };

        return [
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'input' => $key === 'custom'
                ? ['from' => $this->typed('from'), 'to' => $this->typed('to')]
                : ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'errors' => $errors,
        ];
    }

    /**
     * A custom date as typed, kept short so it can be shown back in the form.
     */
    private function typed(string $field): string
    {
        $value = $this->query($field);

        return is_string($value) ? mb_substr($value, 0, 10) : '';
    }
}
