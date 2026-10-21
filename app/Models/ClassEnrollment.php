<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassEnrollment extends Model
{
    protected $fillable = ['class_group_id', 'student_id', 'start_date', 'end_date', 'status', 'exit_reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Matrícula que cobria a data, ainda ativa ou já encerrada. Encerrar não apaga
     * o aluno das chamadas anteriores ao término.
     */
    public function scopeCoveringDate(Builder $query, mixed $date): Builder
    {
        return $query->whereIn('status', ['active', 'ended'])->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
    }
}
