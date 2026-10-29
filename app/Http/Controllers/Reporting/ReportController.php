<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Services\Access\OperationalScope;
use App\Services\Reporting\AttendanceDetailReport;
use App\Services\Reporting\AttendanceListReport;
use App\Services\Reporting\AttendanceReportData;
use App\Services\Reporting\AttendanceSummaryReport;
use App\Services\Reporting\ReportContext;
use App\Services\Reporting\ReportScope;
use App\Services\Reporting\StudentListReport;
use App\Services\Reporting\TechniquesTaughtReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tela Relatórios e downloads nos três níveis: associação, dojo e professor. */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportScope $scope,
        private readonly StudentListReport $students,
        private readonly AttendanceReportData $attendance,
        private readonly AttendanceSummaryReport $summary,
        private readonly AttendanceDetailReport $detail,
        private readonly AttendanceListReport $lists,
        private readonly TechniquesTaughtReport $techniques,
    ) {}

    public function associationTechniques(Request $request, Association $association): StreamedResponse
    {
        return $this->techniquesExport($request, $this->scope->association($request->user(), $association));
    }

    public function dojoTechniques(Request $request, Dojo $dojo): StreamedResponse
    {
        return $this->techniquesExport($request, $this->scope->dojo($request->user(), $dojo));
    }

    public function teacherTechniques(Request $request): StreamedResponse
    {
        return $this->techniquesExport($request, $this->scope->teacher($request->user()));
    }

    public function associationSessionList(Request $request, Association $association, ClassSession $session): Response
    {
        abort_unless($request->user()->hasPermission(ReportScope::PERMISSION, $association->id), 403);
        abort_unless($session->classGroup->association_id === $association->id, 404);

        return $this->lists->session($association, $session, $request->user(), 'association');
    }

    /** Gestor do dojo ou professor da aula com `report.export`, pela mesma regra que abre a aula. */
    public function dojoSessionList(Request $request, Dojo $dojo, ClassSession $session, OperationalScope $operational): Response
    {
        abort_unless($operational->sessions($request->user(), $dojo, ReportScope::PERMISSION)->whereKey($session->id)->exists(), 404);
        $dojo->loadMissing('association');

        return $this->lists->session($dojo->association, $session, $request->user(), $operational->canDojo($request->user(), $dojo, ReportScope::PERMISSION) ? 'dojo' : 'teacher');
    }

    public function associationGrid(Request $request, Association $association): Response
    {
        return $this->grid($request, $this->scope->association($request->user(), $association));
    }

    public function dojoGrid(Request $request, Dojo $dojo): Response
    {
        return $this->grid($request, $this->scope->dojo($request->user(), $dojo));
    }

    public function teacherGrid(Request $request): Response
    {
        return $this->grid($request, $this->scope->teacher($request->user()));
    }

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

    public function associationAttendance(Request $request, Association $association, string $kind): StreamedResponse
    {
        return $this->attendanceExport($request, $this->scope->association($request->user(), $association), $kind);
    }

    public function dojoAttendance(Request $request, Dojo $dojo, string $kind): StreamedResponse
    {
        return $this->attendanceExport($request, $this->scope->dojo($request->user(), $dojo), $kind);
    }

    public function teacherAttendance(Request $request, string $kind): StreamedResponse
    {
        return $this->attendanceExport($request, $this->scope->teacher($request->user()), $kind);
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
        $period = $this->period($request);
        $preview = in_array($request->query('previa'), ['1', 'alunos'], true) ? $this->students->query($context, $filters)->count() : null;
        $attendancePreview = null;
        if ($request->query('previa') === 'frequencia') {
            $built = $this->attendance->build($context, $filters + $period);
            $attendancePreview = ['closed' => $built['sessions']->where('attendance_state', 'closed')->count(), 'open' => $built['sessions']->where('attendance_state', '!=', 'closed')->count(),
                'students' => $built['entries']->pluck('enrollment.student_id')->unique()->count(), 'lines' => $built['entries']->count()];
        }

        return view('reports.index', compact('context', 'filters', 'preview', 'period', 'attendancePreview') + ['statuses' => StudentListReport::STATUSES,
            'limit' => StudentListReport::LIMIT, 'detailLimit' => AttendanceDetailReport::LIMIT]);
    }

    private function grid(Request $request, ReportContext $context): Response
    {
        $data = $request->validate([
            'grade_turma' => ['required', 'integer', Rule::in($context->classes->pluck('id')->all())],
            'mes' => ['required', 'date_format:Y-m'],
        ], ['grade_turma.required' => 'Escolha a turma.']);

        return $this->lists->grid($context, $context->classes->firstWhere('id', (int) $data['grade_turma']), CarbonImmutable::createFromFormat('!Y-m', $data['mes']));
    }

    private function techniquesExport(Request $request, ReportContext $context): StreamedResponse
    {
        $data = $request->validate(['tecnicas_turma' => ['required', 'integer', Rule::in($context->classes->pluck('id')->all())]], ['tecnicas_turma.required' => 'Escolha a turma.']);
        $period = $this->period($request);

        return $this->techniques->export($context, $context->classes->firstWhere('id', (int) $data['tecnicas_turma']), $period['from'], $period['to']);
    }

    private function attendanceExport(Request $request, ReportContext $context, string $kind): StreamedResponse
    {
        $filters = $this->filters($request, $context) + $this->period($request);

        return $kind === 'detalhada' ? $this->detail->export($context, $filters) : $this->summary->export($context, $filters);
    }

    /** Período das frequências: padrão é o mês atual; no máximo 366 dias. @return array{from: CarbonImmutable, to: CarbonImmutable} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
        ], ['ate.after_or_equal' => 'A data final precisa ser igual ou depois da inicial.']);
        $from = isset($data['de']) ? CarbonImmutable::parse($data['de']) : CarbonImmutable::today()->startOfMonth();
        $to = isset($data['ate']) ? CarbonImmutable::parse($data['ate']) : $from->endOfMonth()->startOfDay();
        if ($to->lt($from)) {
            $to = $from->endOfMonth()->startOfDay();
        }
        if ($from->diffInDays($to) >= AttendanceReportData::MAX_DAYS) {
            throw ValidationException::withMessages(['ate' => 'Escolha um período de até '.AttendanceReportData::MAX_DAYS.' dias.']);
        }

        return ['from' => $from, 'to' => $to];
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
