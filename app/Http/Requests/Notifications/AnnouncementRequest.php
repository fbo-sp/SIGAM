<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', 'in:all,dojos,classes,people'],
            'dojo_ids' => ['array', 'max:200'], 'dojo_ids.*' => ['integer'],
            'class_ids' => ['array', 'max:500'], 'class_ids.*' => ['integer'],
            'student_ids' => ['array', 'max:2000'], 'student_ids.*' => ['integer'],
            'teacher_ids' => ['array', 'max:500'], 'teacher_ids.*' => ['integer'],
            'include' => ['array'], 'include.*' => ['in:student,guardian,teacher'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'send_email' => ['nullable', 'boolean'],
            'token' => [$this->routeIs('*.announcements.store') ? 'required' : 'nullable', 'string', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return ['target_type' => 'destino', 'title' => 'título', 'body' => 'mensagem'];
    }
}
