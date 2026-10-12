<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = ['association_id', 'name', 'description', 'requires_expiration', 'status'];

    protected function casts(): array
    {
        return ['requires_expiration' => 'boolean'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
