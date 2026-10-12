<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rank extends Model
{
    protected $fillable = ['martial_art_id', 'name', 'display_name', 'sort_order', 'color_name', 'minimum_age', 'minimum_months', 'status'];

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }
}
