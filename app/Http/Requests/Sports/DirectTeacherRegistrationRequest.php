<?php

namespace App\Http\Requests\Sports;

use App\Rules\Cpf;
use App\Rules\PrivateDocumentFile;
use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DirectTeacherRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $person = (array) $this->input('person', []);
        $this->merge([
            'person_mode' => $this->input('person_mode', 'new'),
            'person' => [...$person,
                'full_name' => trim((string) ($person['full_name'] ?? '')),
                'cpf' => InputNormalizer::digits($person['cpf'] ?? null),
                'email' => InputNormalizer::email($person['email'] ?? null),
                'mobile' => InputNormalizer::digits($person['mobile'] ?? null),
            ],
            'confirm_existing_person' => $this->boolean('confirm_existing_person'),
            'send_invitation' => $this->boolean('send_invitation'),
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'person_mode' => ['required', Rule::in(['new', 'existing'])],
            'person_id' => ['nullable', 'required_if:person_mode,existing', 'integer', 'exists:people,id'],
            'confirm_existing_person' => ['boolean'],
            'person.full_name' => ['nullable', 'required_if:person_mode,new', 'string', 'max:255'],
            'person.cpf' => ['nullable', new Cpf],
            'person.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'person.email' => ['nullable', 'required_if:send_invitation,1', 'email:rfc', 'max:255'],
            'person.mobile' => ['nullable', 'string', 'between:10,11'],
            'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'],
            'rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'federation_registration' => ['nullable', 'string', 'max:80'],
            'certification_expiration' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'send_invitation' => ['boolean'],
            'document_type_id' => ['nullable', 'required_with:document_file', 'integer', 'exists:document_types,id'],
            'document_file' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', new PrivateDocumentFile],
            'document_expiration_date' => ['nullable', 'date'],
        ];
    }
}
