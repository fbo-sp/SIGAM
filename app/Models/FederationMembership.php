<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FederationMembership extends Model
{
    protected $fillable = ['association_id', 'student_id', 'federation_id', 'martial_art_id', 'registration_number', 'reference_year', 'registration_date', 'expiration_date', 'rank_id', 'points', 'status', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['registration_date' => 'date', 'expiration_date' => 'date', 'points' => 'decimal:2'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function federation(): BelongsTo
    {
        return $this->belongsTo(Federation::class);
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function expiryState(): string
    {
        if ($this->expiration_date === null) {
            return 'no_expiration';
        }
        if ($this->expiration_date->lt(today())) {
            return 'expired';
        }
        if ($this->expiration_date->lte(today()->addDays(30))) {
            return 'expiring';
        }

        return 'valid';
    }
}
