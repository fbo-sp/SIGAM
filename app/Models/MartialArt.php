<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MartialArt extends Model
{
    protected $table = 'martial_arts';

    protected $fillable = ['association_id', 'name', 'description', 'status'];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function ranks(): HasMany
    {
        return $this->hasMany(Rank::class)->orderBy('sort_order');
    }
}
