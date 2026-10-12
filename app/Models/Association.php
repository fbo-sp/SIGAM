<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Association extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legal_name',
        'trade_name',
        'cnpj',
        'foundation_date',
        'email',
        'phone',
        'website',
        'postal_code',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'country',
        'status',
        'requires_minor_guardian',
        'notes',
    ];

    protected function casts(): array
    {
        return ['foundation_date' => 'date', 'requires_minor_guardian' => 'boolean'];
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'association_person_roles')
            ->withPivot(['role_type', 'start_date', 'end_date', 'status', 'notes'])
            ->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function dojos(): HasMany
    {
        return $this->hasMany(Dojo::class);
    }

    public function martialArts(): HasMany
    {
        return $this->hasMany(MartialArt::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function financialPlans(): HasMany
    {
        return $this->hasMany(FinancialPlan::class);
    }

    public function gratuityRequests(): HasMany
    {
        return $this->hasMany(GratuityRequest::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }
}
