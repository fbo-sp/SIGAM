<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item de nota de uma faixa no exame: técnica ou conhecimento do currículo, ou a prova teórica (quiz). */
class RankExamItem extends Model
{
    protected $fillable = ['rank_exam_id', 'target_rank_id', 'rank_exam_group_id', 'kind', 'technique_item_id', 'required_level', 'origin', 'selected', 'justification', 'updated_by_user_id', 'sort_order'];

    protected function casts(): array
    {
        return ['selected' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RankExamGroup::class, 'rank_exam_group_id');
    }

    public function technique(): BelongsTo
    {
        return $this->belongsTo(TechniqueItem::class, 'technique_item_id');
    }
}
