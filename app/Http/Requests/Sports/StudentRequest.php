<?php

namespace App\Http\Requests\Sports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['registration_number' => mb_strtoupper(trim((string) $this->registration_number))]);
    }

    public function rules(): array
    {
        $association = $this->route('association');

        return ['person_id' => ['required', 'integer', 'exists:people,id'], 'registration_number' => ['required', 'string', 'max:60', Rule::unique('students')->where('association_id', $association->id)], 'entry_date' => ['required', 'date'], 'primary_dojo_id' => ['required', 'integer', 'exists:dojos,id'], 'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'], 'rank_id' => ['nullable', 'integer', 'exists:ranks,id'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
