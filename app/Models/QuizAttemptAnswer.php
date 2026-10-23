<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttemptAnswer extends Model
{
    protected $fillable = ['quiz_attempt_id', 'quiz_question_id', 'option_ids', 'chosen_option_id', 'is_correct', 'sort_order'];

    protected function casts(): array
    {
        return ['option_ids' => 'array', 'is_correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
