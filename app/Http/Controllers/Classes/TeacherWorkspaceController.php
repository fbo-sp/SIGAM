<?php

namespace App\Http\Controllers\Classes;

use App\Http\Controllers\Controller;
use App\Services\Classes\TeacherWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Abas Turmas e Alunos do professor, juntando todos os dojos em que ele dá aula. */
class TeacherWorkspaceController extends Controller
{
    public function __construct(private readonly TeacherWorkspaceService $workspace) {}

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

        return view('teacher.students', compact('rows', 'search', 'filter', 'counts'));
    }
}
