<?php

namespace App\Http\Requests\Progression;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechniqueStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
