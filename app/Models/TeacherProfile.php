<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherProfile extends Model
{
    protected $fillable = ['person_id', 'registration_number', 'status', 'notes'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function martialArts(): HasMany
    {
        return $this->hasMany(TeacherMartialArt::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeacherInvitation::class);
    }
}
