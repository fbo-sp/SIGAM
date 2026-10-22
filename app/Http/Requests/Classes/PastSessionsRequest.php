<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class PastSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function attributes(): array
    {
        return ['from' => 'data inicial', 'until' => 'data final'];
    }
}
