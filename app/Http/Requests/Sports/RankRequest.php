<?php

namespace App\Http\Requests\Sports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->name), 'display_name' => trim((string) $this->display_name)]);
    }

    public function rules(): array
    {
        $martialArt = $this->route('martialArt');
        $rank = $this->route('rank');

        return ['name' => ['required', 'string', 'max:255', Rule::unique('ranks')->where('martial_art_id', $martialArt->id)->ignore($rank)], 'display_name' => ['required', 'string', 'max:255'], 'sort_order' => ['required', 'integer', 'min:1', Rule::unique('ranks')->where('martial_art_id', $martialArt->id)->ignore($rank)], 'color_name' => ['nullable', 'string', 'max:80'], 'minimum_age' => ['nullable', 'integer', 'min:0', 'max:120'], 'minimum_months' => ['nullable', 'integer', 'min:0', 'max:1200'], 'status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
