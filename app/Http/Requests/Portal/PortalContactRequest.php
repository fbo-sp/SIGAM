<?php

namespace App\Http\Requests\Portal;

use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class PortalContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => InputNormalizer::email($this->email),
            'phone' => InputNormalizer::digits($this->phone),
            'mobile' => InputNormalizer::digits($this->mobile),
            'emergency_contact_name' => InputNormalizer::nullableText($this->emergency_contact_name),
            'emergency_contact_phone' => InputNormalizer::digits($this->emergency_contact_phone),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'between:10,11'],
            'mobile' => ['nullable', 'string', 'between:10,11'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'between:10,11'],
        ];
    }
}
