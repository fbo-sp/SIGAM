<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de nota de uma faixa no exame: a média dos itens do grupo entra na média geral. */
class RankExamGroup extends Model
{
    protected $fillable = ['rank_exam_id', 'target_rank_id', 'name', 'sort_order'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(RankExam::class, 'rank_exam_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RankExamItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
