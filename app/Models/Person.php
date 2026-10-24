<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

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

    /**
     * Link assinado da foto. Só é gerado em telas que já autorizaram ver a pessoa; vale por 6 a 12 horas
     * e fica igual dentro da mesma janela, para o navegador reaproveitar a imagem entre as páginas.
     */
    public function photoUrl(string $size = 'thumb'): ?string
    {
        if (! $this->photo_path) {
            return null;
        }
        $window = 6 * 3600;

        return URL::temporarySignedRoute('people.photo', now()->setTimestamp((intdiv(time(), $window) + 2) * $window), [
            'person' => $this->id, 'size' => $size, 'v' => substr(sha1($this->photo_path), 0, 12),
        ], absolute: false);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim((string) ($this->social_name ?: $this->full_name))) ?: [];
        $first = $words[0] ?? '';
        $last = count($words) > 1 ? end($words) : '';

        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
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
