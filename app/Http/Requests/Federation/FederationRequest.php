<?php

namespace App\Http\Requests\Federation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FederationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['acronym' => mb_strtoupper(trim((string) $this->input('acronym'))), 'state' => $this->filled('state') ? mb_strtoupper(trim((string) $this->input('state'))) : null, 'country' => mb_strtoupper(trim((string) $this->input('country', 'BR')))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $association = $this->route('association');
        $federation = $this->route('federation');

        return [
            'name' => ['required', 'string', 'max:180', Rule::unique('federations', 'name')->where('association_id', $association->id)->ignore($federation?->id)],
            'acronym' => ['required', 'string', 'max:30', Rule::unique('federations', 'acronym')->where('association_id', $association->id)->ignore($federation?->id)],
            'federation_type' => ['required', Rule::in(['state', 'national', 'international', 'other'])],
            'state' => ['nullable', 'string', 'size:2'], 'country' => ['required', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url:http,https', 'max:500'], 'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
