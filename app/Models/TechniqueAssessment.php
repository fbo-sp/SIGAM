<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de avaliação técnica; nunca é alterado nem apagado. */
class TechniqueAssessment extends Model
{
    protected $fillable = ['association_id', 'student_id', 'martial_art_id', 'technique_item_id', 'level', 'teacher_profile_id', 'dojo_id', 'assessed_by_user_id', 'assessed_on', 'notes'];

    protected function casts(): array
    {
        return ['assessed_on' => 'date', 'level' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TechniqueItem::class, 'technique_item_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }
}
