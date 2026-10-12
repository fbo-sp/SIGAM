<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class PortalChampionshipRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['weight' => $this->filled('weight') ? str_replace(',', '.', trim((string) $this->input('weight'))) : null]);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:120'],
            'weight' => ['required', 'decimal:0,2', 'gt:0'],
            'rank_id' => ['required', 'integer', 'exists:ranks,id'],
            'federation_membership_id' => ['nullable', 'integer', 'exists:federation_memberships,id'],
            'team_name' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
