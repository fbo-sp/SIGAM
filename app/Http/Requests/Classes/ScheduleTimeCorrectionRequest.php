<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleTimeCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['start_time' => 'início', 'end_time' => 'fim', 'reason' => 'motivo'];
    }
}
