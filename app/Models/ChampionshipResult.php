<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChampionshipResult extends Model
{
    protected $fillable = ['championship_registration_id', 'participation_confirmed', 'placement', 'medal', 'points', 'walkover', 'disqualified', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['participation_confirmed' => 'boolean', 'points' => 'decimal:2', 'walkover' => 'boolean', 'disqualified' => 'boolean'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ChampionshipRegistration::class, 'championship_registration_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
