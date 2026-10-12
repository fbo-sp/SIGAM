<?php

namespace App\Http\Requests\Sports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_legal_guardian' => $this->boolean('is_legal_guardian'), 'is_financial_guardian' => $this->boolean('is_financial_guardian'), 'is_primary' => $this->boolean('is_primary'), 'can_receive_notifications' => $this->boolean('can_receive_notifications')]);
    }

    public function rules(): array
    {
        return ['guardian_person_id' => ['required', 'integer', 'exists:people,id'], 'relationship_type' => ['required', Rule::in(['father', 'mother', 'tutor', 'legal_guardian', 'financial_guardian', 'emergency_contact', 'other'])], 'is_legal_guardian' => ['boolean'], 'is_financial_guardian' => ['boolean'], 'is_primary' => ['boolean'], 'can_receive_notifications' => ['boolean'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date']];
    }
}
