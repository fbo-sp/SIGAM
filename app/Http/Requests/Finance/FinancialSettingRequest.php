<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $holidays = preg_split('/[\r\n,;]+/', (string) $this->holiday_dates, -1, PREG_SPLIT_NO_EMPTY);
        $this->merge(['holiday_dates' => array_values(array_unique(array_map('trim', $holidays ?: []))), 'discounts_require_approval' => $this->boolean('discounts_require_approval')]);
    }

    public function rules(): array
    {
        return ['nonexistent_day_policy' => ['required', Rule::in(['last_day'])], 'weekend_policy' => ['required', Rule::in(['none', 'next_business_day', 'previous_business_day'])], 'holiday_dates' => ['array', 'max:100'], 'holiday_dates.*' => ['date_format:Y-m-d'], 'discounts_require_approval' => ['boolean']];
    }
}
