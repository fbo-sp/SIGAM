<?php

namespace App\Services\Reporting;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Alcance de quem exporta (decisões de Fabio em 28/10/2026): associação (todos os dojos), gestor do dojo (o dojo) ou
 * professor (as turmas em que é principal ou auxiliar). Filtros do pedido só restringem dentro deste alcance.
 */
final class ReportContext
{
    /**
     * @param  Collection<int, Dojo>  $dojos
     * @param  Collection<int, ClassGroup>  $classes
     */
    public function __construct(
        public readonly string $level,
        public readonly User $user,
        public readonly Association $association,
        public readonly ?Dojo $dojo,
        public readonly Collection $dojos,
        public readonly Collection $classes,
    ) {}

    public function isTeacher(): bool
    {
        return $this->level === 'teacher';
    }

    public function label(): string
    {
        return match ($this->level) {
            'association' => 'Gestor da associação',
            'dojo' => 'Gestor do dojo',
            default => 'Professor',
        };
    }

    /** @return array<string, mixed> parâmetros de rota do contexto */
    public function routeParameters(): array
    {
        return match ($this->level) {
            'association' => ['association' => $this->association],
            'dojo' => ['dojo' => $this->dojo],
            default => [],
        };
    }

    public function routePrefix(): string
    {
        return match ($this->level) {
            'association' => 'associations.reports.',
            'dojo' => 'operation.reports.',
            default => 'teacher.reports.',
        };
    }
}
