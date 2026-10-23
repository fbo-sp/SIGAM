<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizQuestion extends Model
{
    protected $fillable = ['association_id', 'martial_art_id', 'rank_id', 'technique_item_id', 'statement', 'explanation', 'status', 'created_by_user_id'];

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TechniqueItem::class, 'technique_item_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuizQuestionOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
