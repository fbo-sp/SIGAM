<?php

namespace App\Http\Requests\Sports;

use App\Rules\Cpf;
use App\Rules\PrivateDocumentFile;
use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DirectStudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $person = (array) $this->input('person', []);
        $guardian = (array) $this->input('guardian', []);
        $this->merge([
            'person_mode' => $this->input('person_mode', 'new'),
            'person' => [...$person,
                'full_name' => trim((string) ($person['full_name'] ?? '')),
                'cpf' => InputNormalizer::digits($person['cpf'] ?? null),
                'email' => InputNormalizer::email($person['email'] ?? null),
                'mobile' => InputNormalizer::digits($person['mobile'] ?? null),
            ],
            'guardian' => [...$guardian,
                'full_name' => trim((string) ($guardian['full_name'] ?? '')),
                'cpf' => InputNormalizer::digits($guardian['cpf'] ?? null),
                'email' => InputNormalizer::email($guardian['email'] ?? null),
                'mobile' => InputNormalizer::digits($guardian['mobile'] ?? null),
            ],
            'confirm_existing_person' => $this->boolean('confirm_existing_person'),
            'confirm_existing_guardian' => $this->boolean('confirm_existing_guardian'),
            'guardian_is_legal' => $this->boolean('guardian_is_legal'),
            'guardian_is_financial' => $this->boolean('guardian_is_financial'),
            'guardian_is_primary' => $this->boolean('guardian_is_primary'),
            'guardian_notifications' => $this->boolean('guardian_notifications'),
            'make_primary' => $this->boolean('make_primary'),
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
            'person.email' => ['nullable', 'email:rfc', 'max:255'],
            'person.mobile' => ['nullable', 'string', 'between:10,11'],
            'entry_date' => ['required', 'date'],
            'dojo_id' => ['required', 'integer', 'exists:dojos,id'],
            'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'],
            'rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'make_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'class_group_ids' => ['nullable', 'array', 'max:10'],
            'class_group_ids.*' => ['integer', 'distinct'],
            'finish_action' => ['required', Rule::in(['pre_registration', 'activate'])],
            'guardian_mode' => ['required', Rule::in(['none', 'new', 'existing'])],
            'guardian_person_id' => ['nullable', 'required_if:guardian_mode,existing', 'integer', 'exists:people,id'],
            'confirm_existing_guardian' => ['boolean'],
            'guardian.full_name' => ['nullable', 'required_if:guardian_mode,new', 'string', 'max:255'],
            'guardian.cpf' => ['nullable', new Cpf],
            'guardian.email' => ['nullable', 'email:rfc', 'max:255'],
            'guardian.mobile' => ['nullable', 'string', 'between:10,11'],
            'guardian_relationship' => ['nullable', 'required_unless:guardian_mode,none', Rule::in(['father', 'mother', 'tutor', 'legal_guardian', 'financial_guardian', 'emergency_contact', 'other'])],
            'guardian_is_legal' => ['boolean'],
            'guardian_is_financial' => ['boolean'],
            'guardian_is_primary' => ['boolean'],
            'guardian_notifications' => ['boolean'],
            'document_type_id' => ['nullable', 'required_with:document_file', 'integer', 'exists:document_types,id'],
            'document_owner' => ['nullable', 'required_with:document_file', Rule::in(['student', 'guardian'])],
            'document_file' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', new PrivateDocumentFile],
            'document_expiration_date' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->hasFile('document_file') && $this->input('document_owner') === 'guardian'
                && $this->input('guardian_mode', 'none') === 'none') {
                $validator->errors()->add('document_owner', 'Informe um responsável para vincular este documento.');
            }
        }];
    }
}
