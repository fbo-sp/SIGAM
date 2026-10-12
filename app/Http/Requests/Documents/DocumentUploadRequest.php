<?php

namespace App\Http\Requests\Documents;

use App\Rules\PrivateDocumentFile;
use Illuminate\Foundation\Http\FormRequest;

class DocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person_id' => ['nullable', 'integer', 'exists:people,id', 'required_without:student_id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id', 'required_without:person_id'],
            'federation_membership_id' => ['nullable', 'integer', 'exists:federation_memberships,id'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'file' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', new PrivateDocumentFile],
            'issue_date' => ['nullable', 'date'],
            'expiration_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
