<?php

namespace App\Http\Requests\Progression;

use Illuminate\Foundation\Http\FormRequest;

class TechniqueTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['template_key' => ['required', 'string', 'max:80']];
    }
}
