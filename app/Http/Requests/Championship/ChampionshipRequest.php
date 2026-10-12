<?php

namespace App\Http\Requests\Championship;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChampionshipRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['default_fee' => str_replace(',', '.', trim((string) $this->input('default_fee', '0'))), 'allow_competitor' => $this->boolean('allow_competitor'), 'allow_referee' => $this->boolean('allow_referee'), 'allow_assistant' => $this->boolean('allow_assistant')]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dojo_id' => ['nullable', 'integer', 'exists:dojos,id'], 'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'],
            'name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'],
            'championship_type' => ['required', Rule::in(['internal', 'friendly', 'federated', 'official', 'external'])],
            'organizer' => ['required', 'string', 'max:180'], 'location_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'registration_open_at' => ['required', 'date'], 'registration_close_at' => ['required', 'date', 'after:registration_open_at', 'before_or_equal:start_date'],
            'rules_text' => ['nullable', 'string', 'max:50000'], 'default_fee' => ['required', 'decimal:0,2', 'min:0'],
            'allow_competitor' => ['required', 'boolean'], 'allow_referee' => ['required', 'boolean'], 'allow_assistant' => ['required', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'open', 'closed', 'completed', 'cancelled'])],
        ];
    }
}
