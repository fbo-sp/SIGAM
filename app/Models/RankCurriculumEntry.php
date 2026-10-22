<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ajuste do currículo numa faixa: nível exigido, ou null quando o item herdado deixa de ser cobrado. */
class RankCurriculumEntry extends Model
{
    protected $fillable = ['association_id', 'rank_id', 'technique_item_id', 'required_level', 'updated_by_user_id'];

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TechniqueItem::class, 'technique_item_id');
    }
}
