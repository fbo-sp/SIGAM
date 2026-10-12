<?php

namespace App\Http\Requests\Championship;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChampionshipResultRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['participation_confirmed' => $this->boolean('participation_confirmed'), 'walkover' => $this->boolean('walkover'), 'disqualified' => $this->boolean('disqualified'), 'points' => str_replace(',', '.', trim((string) $this->input('points', '0')))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['participation_confirmed' => ['required', 'boolean'], 'placement' => ['nullable', 'integer', 'min:1'], 'medal' => ['nullable', Rule::in(['gold', 'silver', 'bronze', 'other'])], 'points' => ['required', 'decimal:0,2', 'min:0'], 'walkover' => ['required', 'boolean'], 'disqualified' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
