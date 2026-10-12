<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'social_name',
        'cpf',
        'identity_document',
        'identity_issuer',
        'birth_date',
        'email',
        'phone',
        'mobile',
        'postal_code',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function associations(): BelongsToMany
    {
        return $this->belongsToMany(Association::class, 'association_person_roles')
            ->withPivot(['role_type', 'start_date', 'end_date', 'status', 'notes'])
            ->withTimestamps();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function championshipRegistrations(): HasMany
    {
        return $this->hasMany(ChampionshipRegistration::class);
    }
}
