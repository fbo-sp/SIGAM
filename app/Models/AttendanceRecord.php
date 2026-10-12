<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $table = 'attendance';

    protected $fillable = ['class_session_id', 'student_id', 'participation_type', 'status', 'recorded_at', 'recorded_by_user_id', 'notes'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
