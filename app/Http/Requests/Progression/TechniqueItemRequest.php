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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    public function messages(): array
    {
        return ['knowledge_type.required_without' => 'Informe se o grupo é de Conhecimento Teórico ou Prático.'];
    }
}
