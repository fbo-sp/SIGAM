<?php

namespace App\Http\Requests\Federation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FederationMembershipRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['points' => str_replace(',', '.', trim((string) $this->input('points', '0'))), 'registration_number' => trim((string) $this->input('registration_number'))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'], 'federation_id' => ['required', 'integer', 'exists:federations,id'],
            'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'], 'rank_id' => ['required', 'integer', 'exists:ranks,id'],
            'registration_number' => ['required', 'string', 'max:100'], 'reference_year' => ['required', 'integer', 'min:1900', 'max:'.(today()->year + 1)],
            'registration_date' => ['required', 'date'], 'expiration_date' => ['nullable', 'date', 'after_or_equal:registration_date'],
            'points' => ['required', 'decimal:0,2', 'min:0'], 'status' => ['required', Rule::in(['pending', 'active', 'suspended', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
