<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Dojo;
use App\Services\Reporting\ReportContext;
use App\Services\Reporting\ReportScope;
use App\Services\Reporting\StudentListReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tela Relatórios e downloads nos três níveis: associação, dojo e professor. */
class ReportController extends Controller
{
    public function __construct(private readonly ReportScope $scope, private readonly StudentListReport $students) {}

    public function associationIndex(Request $request, Association $association): View
    {
        return $this->index($request, $this->scope->association($request->user(), $association));
    }

    public function associationStudents(Request $request, Association $association): StreamedResponse
    {
        return $this->studentList($request, $this->scope->association($request->user(), $association));
    }

    public function dojoIndex(Request $request, Dojo $dojo): View
    {
        return $this->index($request, $this->scope->dojo($request->user(), $dojo));
    }

    public function dojoStudents(Request $request, Dojo $dojo): StreamedResponse
    {
        return $this->studentList($request, $this->scope->dojo($request->user(), $dojo));
    }

    public function teacherIndex(Request $request): View
    {
        return $this->index($request, $this->scope->teacher($request->user()));
    }

    public function teacherStudents(Request $request): StreamedResponse
    {
        return $this->studentList($request, $this->scope->teacher($request->user()));
    }

    private function index(Request $request, ReportContext $context): View
    {
        $filters = $this->filters($request, $context);
        $preview = $request->boolean('previa') ? $this->students->query($context, $filters)->count() : null;

        return view('reports.index', compact('context', 'filters', 'preview') + ['statuses' => StudentListReport::STATUSES, 'limit' => StudentListReport::LIMIT]);
    }

    private function studentList(Request $request, ReportContext $context): StreamedResponse
    {
        $filters = $this->filters($request, $context);

        return $this->students->export($context, $filters, $this->describe($context, $filters));
    }

    /** @return array{dojo_id: ?int, class_group_id: ?int, situacao: string} */
    private function filters(Request $request, ReportContext $context): array
    {
        $data = $request->validate([
            'dojo_id' => ['nullable', 'integer', Rule::in($context->dojos->pluck('id')->all())],
            'class_group_id' => ['nullable', 'integer', Rule::in($context->classes->pluck('id')->all())],
            'situacao' => ['nullable', Rule::in(array_keys(StudentListReport::STATUSES))],
        ]);

        return ['dojo_id' => isset($data['dojo_id']) ? (int) $data['dojo_id'] : null, 'class_group_id' => isset($data['class_group_id']) ? (int) $data['class_group_id'] : null,
            'situacao' => $data['situacao'] ?? 'ativos'];
    }

    private function describe(ReportContext $context, array $filters): string
    {
        $parts = [
            'Dojo: '.($filters['dojo_id'] ? $context->dojos->firstWhere('id', $filters['dojo_id'])->name : ($context->dojo?->name ?? ($context->isTeacher() ? $context->dojos->pluck('name')->implode(', ') : 'todos'))),
            'Turma: '.($filters['class_group_id'] ? $context->classes->firstWhere('id', $filters['class_group_id'])->name : ($context->isTeacher() ? 'as suas turmas' : 'todas')),
        ];
        if (! $context->isTeacher()) {
            $parts[] = 'Situação: '.mb_strtolower(StudentListReport::STATUSES[$filters['situacao']]);
        }

        return implode(' · ', $parts);
    }
}
