<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class GratuityRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->reason)]);
    }

    public function rules(): array
    {
        return ['student_id' => ['required', 'integer', 'exists:students,id'], 'dojo_id' => ['required', 'integer', 'exists:dojos,id'], 'reason' => ['required', 'string', 'max:255'], 'justification' => ['required', 'string', 'min:10', 'max:10000'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date']];
    }
}
