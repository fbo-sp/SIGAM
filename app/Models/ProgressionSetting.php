<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parâmetros do acompanhamento técnico por associação (quiz e lembrete de avaliação). */
class ProgressionSetting extends Model
{
    public const DEFAULTS = ['quiz_question_count' => 10, 'quiz_minimum_score' => 70, 'assessment_reminder_weeks' => 8];

    protected $fillable = ['association_id', 'quiz_question_count', 'quiz_minimum_score', 'assessment_reminder_weeks', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['quiz_question_count' => 'integer', 'quiz_minimum_score' => 'integer', 'assessment_reminder_weeks' => 'integer'];
    }

    public static function for(int $associationId): self
    {
        return self::query()->firstWhere('association_id', $associationId) ?? new self(['association_id' => $associationId, ...self::DEFAULTS]);
    }
}
