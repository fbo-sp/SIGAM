<?php

namespace App\Services\Reporting;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\Classes\TeacherWorkspaceService;
use Illuminate\Support\Collection;

/** Monta o alcance dos relatórios a partir das mesmas regras de acesso das telas. */
class ReportScope
{
    public const PERMISSION = 'report.export';

    public function __construct(private readonly OperationalScope $scope, private readonly TeacherWorkspaceService $workspace) {}

    public function association(User $user, Association $association): ReportContext
    {
        abort_unless($user->hasPermission(self::PERMISSION, $association->id), 403);
        $dojos = Dojo::query()->where('association_id', $association->id)->orderBy('name')->get();

        return new ReportContext('association', $user, $association, null, $dojos, $this->groups(ClassGroup::query()->where('association_id', $association->id)));
    }

    public function dojo(User $user, Dojo $dojo): ReportContext
    {
        abort_unless($this->scope->canDojo($user, $dojo, self::PERMISSION), 403);
        $dojo->loadMissing('association');

        return new ReportContext('dojo', $user, $dojo->association, $dojo, collect([$dojo]),
            $this->groups(ClassGroup::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)));
    }

    /** Professor: só as turmas em que é principal ou auxiliar vigente, nos dojos em que tem a concessão do relatório. */
    public function teacher(User $user): ReportContext
    {
        $allowed = [];
        $classes = $this->workspace->classes($user, false)
            ->filter(fn (array $item) => $item['dojo'] && $item['url'] && ($allowed[$item['dojo']->id] ??= $this->scope->canTeacher($user, $item['dojo'], self::PERMISSION)))
            ->map(fn (array $item) => $item['class'])->values();
        abort_if($classes->isEmpty(), 403);
        $dojos = $classes->pluck('dojo')->unique('id')->sortBy('name')->values();

        return new ReportContext('teacher', $user, $dojos->first()->association, null, $dojos, $classes);
    }

    /** @return Collection<int, ClassGroup> */
    private function groups($query): Collection
    {
        return $query->where('status', 'active')->with('dojo:id,name')->orderBy('name')->get();
    }
}
