<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentGuardian extends Model
{
    protected $fillable = ['student_id', 'guardian_person_id', 'relationship_type', 'is_legal_guardian', 'is_financial_guardian', 'is_primary', 'can_receive_notifications', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['is_legal_guardian' => 'boolean', 'is_financial_guardian' => 'boolean', 'is_primary' => 'boolean', 'can_receive_notifications' => 'boolean', 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'guardian_person_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
