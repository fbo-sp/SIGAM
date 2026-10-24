<?php

namespace App\Http\Requests\People;

use App\Rules\Cpf;
use App\Services\People\PersonPhotoService;
use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => trim((string) $this->full_name),
            'social_name' => InputNormalizer::nullableText($this->social_name),
            'cpf' => InputNormalizer::digits($this->cpf),
            'email' => InputNormalizer::email($this->email),
            'phone' => InputNormalizer::digits($this->phone),
            'mobile' => InputNormalizer::digits($this->mobile),
            'postal_code' => InputNormalizer::digits($this->postal_code),
            'emergency_contact_phone' => InputNormalizer::digits($this->emergency_contact_phone),
            'state' => mb_strtoupper(trim((string) $this->state)),
            'country' => mb_strtoupper(trim((string) ($this->country ?: 'BR'))),
        ]);
    }

    public function rules(): array
    {
        $person = $this->route('person');

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'social_name' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', new Cpf, Rule::unique('people', 'cpf')->ignore($person)],
            'identity_document' => ['nullable', 'string', 'max:40'],
            'identity_issuer' => ['nullable', 'string', 'max:40'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'between:10,11'],
            'mobile' => ['nullable', 'string', 'between:10,11'],
            'postal_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:30'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'size:2'],
            'country' => ['required', 'size:2'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'between:10,11'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'photo' => PersonPhotoService::RULES,
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['cpf.unique' => 'Este CPF já pertence a uma pessoa cadastrada. Pesquise antes de criar outro vínculo.'];
    }
}
