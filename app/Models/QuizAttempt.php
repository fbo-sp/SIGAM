<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tentativa de quiz (treino ou oficial). As perguntas e a ordem das alternativas ficam congeladas nas respostas. */
class QuizAttempt extends Model
{
    protected $fillable = ['association_id', 'student_id', 'martial_art_id', 'rank_id', 'mode', 'quiz_release_id', 'user_id', 'status', 'question_count', 'correct_count', 'score', 'minimum_score', 'passed', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'passed' => 'boolean'];
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAttemptAnswer::class)->orderBy('sort_order');
    }
}
