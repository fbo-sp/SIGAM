<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    protected $fillable = ['class_group_id', 'class_schedule_id', 'rescheduled_from_id', 'session_date', 'start_time', 'end_time', 'planned_teacher_id', 'actual_teacher_id', 'status', 'attendance_state', 'closed_by_user_id', 'closed_at', 'reopened_by_user_id', 'reopened_at', 'reopen_reason', 'cancellation_reason', 'notes'];

    protected function casts(): array
    {
        return ['session_date' => 'date', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    /** A chamada só pode ser registrada ou fechada a partir do dia da aula. */
    public function isBeforeAttendanceDay(): bool
    {
        return $this->session_date->isAfter(today());
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class, 'class_schedule_id');
    }

    public function plannedTeacher(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'planned_teacher_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(ClassSessionVisitor::class);
    }
}
