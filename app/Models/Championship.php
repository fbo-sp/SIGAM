<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Championship extends Model
{
    use SoftDeletes;

    protected $fillable = ['association_id', 'dojo_id', 'martial_art_id', 'name', 'description', 'championship_type', 'organizer', 'location_name', 'start_date', 'end_date', 'registration_open_at', 'registration_close_at', 'rules_text', 'default_fee', 'allow_competitor', 'allow_referee', 'allow_assistant', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'registration_open_at' => 'datetime', 'registration_close_at' => 'datetime', 'default_fee' => 'decimal:2', 'allow_competitor' => 'boolean', 'allow_referee' => 'boolean', 'allow_assistant' => 'boolean'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ChampionshipRegistration::class);
    }

    public function dojoParticipations(): HasMany
    {
        return $this->hasMany(ChampionshipDojoParticipation::class);
    }

    public function acceptsRegistrations(): bool
    {
        return $this->status === 'open' && now()->betweenIncluded($this->registration_open_at, $this->registration_close_at);
    }
}
