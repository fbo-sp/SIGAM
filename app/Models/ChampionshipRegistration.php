<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChampionshipRegistration extends Model
{
    protected $fillable = ['association_id', 'championship_id', 'person_id', 'student_id', 'dojo_id', 'federation_membership_id', 'participation_type', 'category', 'age_at_event', 'weight', 'rank_id', 'team_name', 'referee_level', 'assistant_function', 'registration_fee', 'status', 'charge_id', 'approved_by', 'approved_at', 'decision_reason', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'registration_fee' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function federationMembership(): BelongsTo
    {
        return $this->belongsTo(FederationMembership::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function result(): HasOne
    {
        return $this->hasOne(ChampionshipResult::class);
    }
}
