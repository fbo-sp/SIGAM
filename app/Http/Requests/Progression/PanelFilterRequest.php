<?php

namespace App\Http\Requests\Progression;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class PanelFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modalidade' => ['nullable', 'integer'],
            'turma' => ['nullable', 'integer'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
        ];
    }

    /** Período do painel: padrão últimos 90 dias, no máximo um ano. @return array{0: Carbon, 1: Carbon} */
    public function period(): array
    {
        $until = $this->validated('ate') ? Carbon::parse($this->validated('ate')) : today();
        $from = $this->validated('de') ? Carbon::parse($this->validated('de')) : $until->copy()->subDays(90);

        return [$from->max($until->copy()->subYear()), $until];
    }
}
