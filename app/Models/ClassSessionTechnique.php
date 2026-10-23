<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSessionTechnique extends Model
{
    protected $fillable = ['association_id', 'class_session_id', 'technique_item_id', 'marked_by_user_id', 'removed_at', 'removed_by_user_id'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TechniqueItem::class, 'technique_item_id');
    }
}
