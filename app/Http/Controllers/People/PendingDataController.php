<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Dojo;
use App\Services\Access\OperationalScope;
use App\Services\People\PendingDataService;
use App\Services\Reporting\ReportContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tela Cadastros incompletos nos três níveis; filtros só restringem dentro do alcance. */
class PendingDataController extends Controller
{
    public function __construct(private readonly PendingDataService $pending, private readonly OperationalScope $scope) {}

    public function association(Request $request, Association $association): View
    {
        return $this->index($request, $this->pending->association($request->user(), $association));
    }

    public function dojo(Request $request, Dojo $dojo): View
    {
        return $this->index($request, $this->pending->dojo($request->user(), $dojo));
    }

    public function teacher(Request $request): View
    {
        return $this->index($request, $this->pending->teacher($request->user()));
    }

    private function index(Request $request, ?ReportContext $context): View
    {
        abort_if($context === null, 403);
        $validated = $request->validate([
            'dojo' => ['nullable', 'integer'],
            'turma' => ['nullable', 'integer'],
            'busca' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(PendingDataService::ITEMS))],
        ]);
        $filters = [
            'dojo_id' => $context->level === 'association' && $context->dojos->contains('id', (int) ($validated['dojo'] ?? 0)) ? (int) $validated['dojo'] : null,
            'class_group_id' => $context->classes->contains('id', (int) ($validated['turma'] ?? 0)) ? (int) $validated['turma'] : null,
            'search' => trim($validated['busca'] ?? '') ?: null,
        ];
        $rows = $this->pending->list($context, $filters);
        $counts = $this->pending->counts($rows);
        $type = $validated['tipo'] ?? null;
        $shown = $type ? $rows->filter(fn (array $row) => in_array($type, $row['items'], true))->values() : $rows;
        $user = $request->user();
        $canFix = $context->level === 'association' ? $user->hasPermission('student.view', $context->association->id)
            : ($context->dojo ? $this->scope->canDojo($user, $context->dojo, 'student.manage') : false);

        return view('people.pending', [
            'context' => $context, 'rows' => $shown, 'total' => $rows->count(), 'counts' => $counts, 'type' => $type, 'filters' => $filters, 'canFix' => $canFix,
            'action' => match ($context->level) {
                'association' => route('associations.pending', $context->association),
                'dojo' => route('operation.pending', $context->dojo),
                default => route('teacher.pending'),
            },
        ]);
    }
}
