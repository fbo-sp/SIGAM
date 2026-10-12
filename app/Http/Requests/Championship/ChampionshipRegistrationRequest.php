<?php

namespace App\Http\Requests\Championship;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChampionshipRegistrationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['weight' => $this->filled('weight') ? str_replace(',', '.', trim((string) $this->input('weight'))) : null]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person_id' => ['required', 'integer', 'exists:people,id'], 'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'dojo_id' => ['nullable', 'integer', 'exists:dojos,id'], 'federation_membership_id' => ['nullable', 'integer', 'exists:federation_memberships,id'],
            'participation_type' => ['required', Rule::in(['competitor', 'referee', 'assistant'])],
            'category' => ['nullable', 'string', 'max:120'], 'weight' => ['nullable', 'decimal:0,2', 'gt:0'], 'rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'team_name' => ['nullable', 'string', 'max:180'], 'referee_level' => ['nullable', 'string', 'max:100'],
            'assistant_function' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
