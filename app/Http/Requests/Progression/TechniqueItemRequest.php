<?php

namespace App\Http\Requests\Progression;

use App\Models\TechniqueItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechniqueItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->name),
            'original_name' => trim((string) $this->original_name) ?: null,
            'description' => trim((string) $this->description) ?: null,
            'translation' => trim((string) $this->translation) ?: null,
            'key_points' => trim((string) $this->key_points) ?: null,
            'common_error' => trim((string) $this->common_error) ?: null,
            'safety_note' => trim((string) $this->safety_note) ?: null,
            'steps' => trim((string) $this->steps) ?: null,
            'video_url' => trim((string) $this->video_url) ?: null,
            'parent_id' => $this->parent_id ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(TechniqueItem::KINDS)],
            'knowledge_type' => ['required_without:parent_id', 'nullable', Rule::in(TechniqueItem::KNOWLEDGE_TYPES)],
            'name' => ['required', 'string', 'max:160'],
            'original_name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'video_url' => ['nullable', 'url:http,https', 'max:500'],
            'translation' => ['nullable', 'string', 'max:160'],
            'key_points' => ['nullable', 'string', 'max:1500', fn ($attribute, $value, $fail) => count(preg_split('/\R/', trim((string) $value))) > 5 ? $fail('Use até 5 pontos, um por linha.') : null],
            'common_error' => ['nullable', 'string', 'max:300'],
            'safety_note' => ['nullable', 'string', 'max:300'],
            'steps' => ['nullable', 'string', 'max:1500', fn ($attribute, $value, $fail) => count(preg_split('/\R/', trim((string) $value))) > 5 ? $fail('Use até 5 passos, um por linha.') : null],
            'sheet' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'],
            'remove_sheet' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    public function messages(): array
    {
        return ['knowledge_type.required_without' => 'Informe se o grupo é de Conhecimento Teórico ou Prático.'];
    }
}
