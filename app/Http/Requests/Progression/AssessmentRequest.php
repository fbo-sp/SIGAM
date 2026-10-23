<?php

namespace App\Http\Requests\Progression;

use App\Support\Progression\AssessmentLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => trim((string) $this->notes) ?: null]);
    }

    public function rules(): array
    {
        return [
            'levels' => ['nullable', 'array', 'max:500'],
            'levels.*' => ['nullable', 'integer', Rule::in(array_keys(AssessmentLevel::labels()))],
            'assessed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['assessed_on.before_or_equal' => 'A avaliação não pode ter data futura.'];
    }
}
