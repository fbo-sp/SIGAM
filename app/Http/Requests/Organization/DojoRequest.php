<?php

namespace App\Http\Requests\Organization;

use App\Support\Normalization\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DojoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->code)),
            'name' => trim((string) $this->name),
            'email' => InputNormalizer::email($this->email),
            'phone' => InputNormalizer::digits($this->phone),
            'postal_code' => InputNormalizer::digits($this->postal_code),
            'state' => mb_strtoupper(trim((string) $this->state)),
            'country' => mb_strtoupper(trim((string) ($this->country ?: 'BR'))),
        ]);
    }

    public function rules(): array
    {
        $association = $this->route('association');
        $dojo = $this->route('dojo');

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('dojos')->where('association_id', $association->id)->ignore($dojo)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'between:10,11'],
            'postal_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:30'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'size:2'],
            'country' => ['required', 'size:2'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'started_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
