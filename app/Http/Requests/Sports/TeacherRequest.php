<?php

namespace App\Http\Requests\Sports;

use App\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profile = TeacherProfile::query()->where('person_id', $this->input('person_id'))->first();

        return ['person_id' => ['required', 'integer', 'exists:people,id'], 'registration_number' => ['nullable', 'string', 'max:60', Rule::unique('teacher_profiles')->ignore($profile)], 'martial_art_id' => ['required', 'integer', 'exists:martial_arts,id'], 'rank_id' => ['nullable', 'integer', 'exists:ranks,id'], 'federation_registration' => ['nullable', 'string', 'max:80'], 'certification_expiration' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
