<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dojo_id' => ['required', 'integer', 'exists:dojos,id'],
            'import_type' => ['required', Rule::in(['students', 'attendance'])],
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx'],
        ];
    }
}
