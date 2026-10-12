<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['discount_value' => str_replace(',', '.', (string) $this->discount_value), 'reason' => trim((string) $this->reason)]);
    }

    public function rules(): array
    {
        return ['student_id' => ['required', 'integer', 'exists:students,id'], 'student_plan_id' => ['nullable', 'integer', 'exists:student_plans,id'], 'discount_type' => ['required', Rule::in(['percentage', 'fixed'])], 'discount_value' => ['required', 'decimal:0,2', 'gt:0'], 'reason' => ['required', 'string', 'max:255'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date']];
    }
}
