<?php

namespace App\Http\Requests\Sports;

use App\Support\Sports\RankTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MartialArtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $templateKey = trim((string) $this->input('template_key'));
        $name = trim((string) $this->input('name'));
        $description = trim((string) $this->input('description'));

        if ($this->route('martialArt') === null && $templateKey !== '') {
            $template = app(RankTemplateCatalog::class)->find($templateKey);
            $name = $name !== '' ? $name : (string) ($template['default_name'] ?? '');
            $description = $description !== '' ? $description : (string) ($template['description'] ?? '');
        }

        $this->merge(['name' => $name, 'description' => $description ?: null, 'template_key' => $templateKey ?: null]);
    }

    public function rules(): array
    {
        $association = $this->route('association');
        $martialArt = $this->route('martialArt');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('martial_arts')->where('association_id', $association->id)->ignore($martialArt)],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'template_key' => $martialArt === null
                ? ['nullable', 'string', Rule::in(app(RankTemplateCatalog::class)->keys())]
                : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'template_key.in' => 'Selecione uma configuração inicial disponível.',
            'template_key.prohibited' => 'O template só pode ser usado na criação da modalidade.',
        ];
    }
}
