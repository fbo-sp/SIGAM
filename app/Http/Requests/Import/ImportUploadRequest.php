<?php

namespace App\Http\Requests\Import;

use App\Services\Import\AssistedImportService;
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
            'import_type' => ['required', Rule::in(array_keys(AssistedImportService::TYPES))],
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx'],
        ];
    }
}
