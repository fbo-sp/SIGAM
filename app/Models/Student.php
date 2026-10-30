<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $fillable = ['association_id', 'person_id', 'registration_number', 'entry_date', 'primary_dojo_id', 'status', 'requires_guardian', 'exit_date', 'exit_reason', 'notes'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'exit_date' => 'date', 'requires_guardian' => 'boolean'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function primaryDojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class, 'primary_dojo_id');
    }

    /** Menor de 18 anos ou marcado como "precisa de responsável mesmo sendo maior de idade". Sem nascimento, só a marcação conta. */
    public function needsGuardian(): bool
    {
        $birth = $this->person?->birth_date;

        return $this->requires_guardian || ($birth !== null && $birth->age < 18);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function dojoHistory(): HasMany
    {
        return $this->hasMany(StudentDojoHistory::class);
    }

    public function martialArts(): HasMany
    {
        return $this->hasMany(StudentMartialArt::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function studentPlans(): HasMany
    {
        return $this->hasMany(StudentPlan::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function gratuityRequests(): HasMany
    {
        return $this->hasMany(GratuityRequest::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function federationMemberships(): HasMany
    {
        return $this->hasMany(FederationMembership::class);
    }

    public function championshipRegistrations(): HasMany
    {
        return $this->hasMany(ChampionshipRegistration::class);
    }

    public function sessionVisits(): HasMany
    {
        return $this->hasMany(ClassSessionVisitor::class);
    }
}
