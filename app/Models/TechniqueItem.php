<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechniqueItem extends Model
{
    public const KINDS = ['group', 'item'];

    public const KNOWLEDGE_TYPES = ['theoretical', 'practical'];

    protected $fillable = ['association_id', 'martial_art_id', 'parent_id', 'kind', 'knowledge_type', 'name', 'original_name', 'description', 'video_url', 'sort_order', 'status'];

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function knowledgeLabel(): string
    {
        return $this->knowledge_type === 'theoretical' ? 'Conhecimento Teórico' : 'Conhecimento Prático';
    }
}
