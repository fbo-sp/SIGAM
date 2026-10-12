<?php

namespace App\Http\Requests\Organization;

use App\Rules\Cnpj;
use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssociationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'legal_name' => trim((string) $this->legal_name),
            'trade_name' => trim((string) $this->trade_name),
            'cnpj' => InputNormalizer::digits($this->cnpj),
            'email' => InputNormalizer::email($this->email),
            'phone' => InputNormalizer::digits($this->phone),
            'postal_code' => InputNormalizer::digits($this->postal_code),
            'state' => mb_strtoupper(trim((string) $this->state)),
            'country' => mb_strtoupper(trim((string) ($this->country ?: 'BR'))),
            'requires_minor_guardian' => $this->has('requires_minor_guardian')
                ? $this->boolean('requires_minor_guardian')
                : ($this->route('association')?->requires_minor_guardian ?? true),
        ]);
    }

    public function rules(): array
    {
        $association = $this->route('association');

        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', new Cnpj, Rule::unique('associations', 'cnpj')->ignore($association)],
            'foundation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'between:10,11'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'postal_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:30'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'size:2'],
            'country' => ['required', 'size:2'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'requires_minor_guardian' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
