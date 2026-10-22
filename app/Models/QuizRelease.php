<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Avaliação oficial do quiz liberada pelo professor: vale uma tentativa (status open, used ou cancelled). */
class QuizRelease extends Model
{
    protected $fillable = ['association_id', 'student_id', 'rank_id', 'teacher_profile_id', 'dojo_id', 'released_by_user_id', 'status'];

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function attempt(): HasOne
    {
        return $this->hasOne(QuizAttempt::class);
    }
}
