<?php

namespace App\Http\Controllers\Classes;

use App\Http\Controllers\Controller;
use App\Models\Dojo;
use App\Services\Access\OperationalScope;
use App\Services\Classes\TeacherWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Abas Turmas e Alunos do professor, juntando todos os dojos em que ele dá aula. */
class TeacherWorkspaceController extends Controller
{
    public function __construct(private readonly TeacherWorkspaceService $workspace, private readonly OperationalScope $scope) {}

    public function classes(Request $request): View
    {
        abort_unless($this->workspace->usesNavigation($request->user()), 403);

        return view('teacher.classes', ['classes' => $this->workspace->classes($request->user())]);
    }

    public function students(Request $request): View
    {
        abort_unless($this->workspace->usesNavigation($request->user()), 403);
        $validated = $request->validate(['busca' => ['nullable', 'string', 'max:120'], 'filtro' => ['nullable', 'in:faltando,prontos']]);
        $search = trim($validated['busca'] ?? '');
        $filter = $validated['filtro'] ?? null;
        $rows = $this->workspace->students($request->user(), $search !== '' ? $search : null);
        $counts = ['faltando' => $rows->where('absent', true)->count(), 'prontos' => $rows->where('ready', true)->count()];
        $rows = match ($filter) {
            'faltando' => $rows->where('absent', true)->values(),
            'prontos' => $rows->where('ready', true)->values(),
            default => $rows,
        };

        $user = $request->user();
        // Dojos em que a conta pode enviar aviso; a tela de envio revalida tudo no servidor.
        $announceDojos = $rows->pluck('dojo')->unique('id')
            ->filter(fn (Dojo $dojo) => $this->scope->canDojo($user, $dojo, 'announcement.send') || $this->scope->canTeacher($user, $dojo, 'announcement.send'))
            ->keyBy('id');
        $announceGroups = $rows->filter(fn (array $row) => $announceDojos->has($row['dojo']->id))
            ->groupBy(fn (array $row) => $row['dojo']->id)
            ->map(fn ($set, $dojoId) => ['dojo' => $announceDojos[$dojoId], 'student_ids' => $set->map(fn (array $row) => $row['student']->id)->unique()->values()->all()])
            ->values();

        return view('teacher.students', compact('rows', 'search', 'filter', 'counts', 'announceDojos', 'announceGroups'));
    }
}
