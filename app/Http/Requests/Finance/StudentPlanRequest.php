<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StudentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['student_id' => ['required', 'integer', 'exists:students,id'], 'dojo_id' => ['required', 'integer', 'exists:dojos,id'], 'financial_plan_id' => ['required', 'integer', 'exists:financial_plans,id'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'preferred_due_day' => ['required', 'integer', 'between:1,31']];
    }
}
