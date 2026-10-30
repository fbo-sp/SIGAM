<?php

namespace App\Services\Exams;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\RankExam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem está nos exames (decisões de Fabio em 30/10/2026): gestor da associação (todos os exames), gestor do dojo
 * (exames do dojo, das turmas e dos alunos dele) ou professor da banca (só os exames em que avalia).
 */
final class ExamContext
{
    public function __construct(
        public readonly string $level,
        public readonly User $user,
        public readonly Association $association,
        public readonly ?Dojo $dojo,
        public readonly ?int $teacherId,
    ) {}

    public function isTeacher(): bool
    {
        return $this->level === 'teacher';
    }

    /** Gestores criam, editam, escolhem participantes, organizam grupos, abrem e cancelam. */
    public function canManage(): bool
    {
        return ! $this->isTeacher();
    }

    /** @return Builder<RankExam> */
    public function exams(): Builder
    {
        $query = RankExam::query()->where('association_id', $this->association->id);

        return match ($this->level) {
            'association' => $query,
            'dojo' => $query->where('dojo_id', $this->dojo->id),
            default => $query->whereIn('status', ['draft', 'open', 'closed'])->where(fn (Builder $exam) => $exam
                ->whereHas('examiners', fn (Builder $examiner) => $examiner->whereKey($this->teacherId))
                ->orWhere(fn (Builder $classes) => $classes->where('class_teachers_examine', true)->whereIn('id', ExamScope::examsTaughtBy($this->teacherId)))),
        };
    }

    /** @return array<string, mixed> */
    public function routeParameters(array $extra = []): array
    {
        return match ($this->level) {
            'association' => ['association' => $this->association],
            'dojo' => ['dojo' => $this->dojo],
            default => [],
        } + $extra;
    }

    public function route(string $name, array $extra = []): string
    {
        $prefix = match ($this->level) {
            'association' => 'associations.exams.',
            'dojo' => 'operation.exams.',
            default => 'teacher.exams.',
        };

        return route($prefix.$name, $this->routeParameters($extra));
    }

    public function label(): string
    {
        return match ($this->level) {
            'association' => $this->association->trade_name,
            'dojo' => $this->dojo->name,
            default => 'Banca',
        };
    }
}
