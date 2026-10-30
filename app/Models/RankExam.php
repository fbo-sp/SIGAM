<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Exame de graduação: período, nível (associação, dojo, turma ou aluno), banca, participantes e conteúdo por faixa. */
class RankExam extends Model
{
    public const SCOPES = ['association' => 'Associação', 'dojo' => 'Dojo', 'class' => 'Turma', 'student' => 'Aluno'];

    public const STATUSES = ['draft' => 'Rascunho', 'open' => 'Aberto', 'closed' => 'Encerrado', 'cancelled' => 'Cancelado'];

    protected $fillable = [
        'association_id', 'martial_art_id', 'name', 'scope_type', 'dojo_id', 'class_group_id', 'student_id', 'justification',
        'starts_on', 'ends_on', 'minimum_average', 'class_teachers_examine', 'quiz_enabled', 'quiz_question_count', 'quiz_time_limit_minutes',
        'status', 'created_by_user_id', 'opened_at', 'opened_by_user_id', 'cancelled_at', 'cancelled_by_user_id', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'minimum_average' => 'decimal:2', 'class_teachers_examine' => 'boolean',
            'quiz_enabled' => 'boolean', 'opened_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function examiners(): BelongsToMany
    {
        return $this->belongsToMany(TeacherProfile::class, 'rank_exam_examiners')->withTimestamps();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RankExamParticipant::class);
    }

    /** Participantes inscritos (sem os retirados). */
    public function enrolled(): HasMany
    {
        return $this->participants()->where('status', '!=', 'removed');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(RankExamGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RankExamItem::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'open'], true);
    }

    public function scopeLabel(): string
    {
        return match ($this->scope_type) {
            'dojo' => $this->dojo?->name ?? 'Dojo',
            'class' => trim(($this->classGroup?->name ?? 'Turma').($this->dojo ? ' ('.$this->dojo->name.')' : '')),
            'student' => 'Aluno',
            default => 'Associação',
        };
    }
}
