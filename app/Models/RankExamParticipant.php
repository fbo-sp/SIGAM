<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aluno inscrito no exame, com a faixa atual e a de destino no momento da inscrição. Retirar não apaga (status removed). */
class RankExamParticipant extends Model
{
    protected $fillable = ['rank_exam_id', 'student_id', 'student_martial_art_id', 'current_rank_id', 'target_rank_id', 'eligible', 'unmet', 'inclusion_reason', 'status', 'added_by_user_id', 'removed_at', 'removed_by_user_id'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'unmet' => 'array', 'removed_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(RankExam::class, 'rank_exam_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function currentRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'current_rank_id');
    }

    public function targetRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'target_rank_id');
    }
}
