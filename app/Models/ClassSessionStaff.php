<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Professor da aula (`lead`) ou auxiliar presente (`assistant`), registrado ao finalizar a chamada. */
class ClassSessionStaff extends Model
{
    protected $table = 'class_session_staff';

    protected $fillable = ['class_session_id', 'teacher_profile_id', 'role', 'recorded_by_user_id'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }
}
