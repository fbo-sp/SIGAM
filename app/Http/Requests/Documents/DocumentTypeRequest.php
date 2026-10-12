<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->name), 'requires_expiration' => $this->boolean('requires_expiration')]);
    }

    public function rules(): array
    {
        $association = $this->route('association');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('document_types')->where('association_id', $association->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_expiration' => ['boolean'],
        ];
    }
}
