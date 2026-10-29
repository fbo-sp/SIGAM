<?php

namespace App\Services\Reporting;

use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\AuditService;
use App\Support\Export\XlsxReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório 1, lista de alunos em Excel. O professor recebe a mesma lista sem CPF e e-mail (decisão de Fabio em
 * 28/10/2026); o alcance filtra no SQL antes de contar ou carregar os alunos.
 */
class StudentListReport
{
    public const STATUSES = ['ativos' => 'Ativos e pré-cadastro', 'so_ativos' => 'Só ativos', 'todos' => 'Todos, inclusive inativos'];

    public const LIMIT = 5000;

    private const STATUS_LABELS = ['active' => 'Ativo', 'pre_registration' => 'Pré-cadastro', 'inactive' => 'Inativo'];

    public function __construct(private readonly AuditService $audit) {}

    /** @param  array{dojo_id: ?int, class_group_id: ?int, situacao: string}  $filters */
    public function query(ReportContext $context, array $filters): Builder
    {
        $today = today()->toDateString();
        $currentDojo = fn (Builder $history) => $history->whereDate('start_date', '<=', $today)
            ->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today));
        $activeEnrollment = fn (Builder $enrollment) => $enrollment->where('status', 'active')
            ->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today));

        $query = Student::query()->where('association_id', $context->association->id);
        if ($context->isTeacher()) {
            $query->where('status', 'active')
                ->whereHas('enrollments', fn (Builder $enrollment) => $activeEnrollment($enrollment)->whereIn('class_group_id', $context->classes->pluck('id')));
        } elseif ($context->dojo) {
            $query->whereHas('dojoHistory', fn (Builder $history) => $currentDojo($history)->where('dojo_id', $context->dojo->id));
        }
        if ($filters['dojo_id']) {
            $query->whereHas('dojoHistory', fn (Builder $history) => $currentDojo($history)->where('dojo_id', $filters['dojo_id']));
        }
        if ($filters['class_group_id']) {
            $query->whereHas('enrollments', fn (Builder $enrollment) => $activeEnrollment($enrollment)->where('class_group_id', $filters['class_group_id']));
        }

        return match ($context->isTeacher() ? 'todos' : $filters['situacao']) {
            'so_ativos' => $query->where('status', 'active'),
            'ativos' => $query->whereIn('status', ['active', 'pre_registration']),
            default => $query,
        };
    }

    /** @return list<array{0: string, 1: int}> */
    public function columns(ReportContext $context): array
    {
        $columns = [['Matrícula', 20], ['Nome', 30], ['Nascimento', 12], ['Idade', 7], ['Dojo', 18], ['Modalidade', 12], ['Faixa', 16], ['Turmas atuais', 32],
            ['Situação', 13], ['Entrada', 11], ['CPF', 15], ['E-mail', 28], ['Celular do aluno', 16], ['Responsável principal', 26], ['Telefone do responsável', 18]];

        return $context->isTeacher() ? array_values(array_filter($columns, fn (array $column) => ! in_array($column[0], ['CPF', 'E-mail'], true))) : $columns;
    }

    /** @return Collection<int, array<int, mixed>> */
    public function rows(ReportContext $context, array $filters): Collection
    {
        $today = today()->toDateString();
        $classIds = $context->classes->pluck('id');
        $students = $this->query($context, $filters)->with([
            'person:id,full_name,social_name,cpf,birth_date,email,phone,mobile',
            'dojoHistory' => fn ($history) => $history->whereDate('start_date', '<=', $today)->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->with('dojo:id,name'),
            'martialArts' => fn ($arts) => $arts->where('status', 'active')->with(['martialArt:id,name', 'currentRank:id,display_name']),
            'enrollments' => fn ($enrollment) => $enrollment->where('status', 'active')->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                ->when($context->isTeacher(), fn ($scoped) => $scoped->whereIn('class_group_id', $classIds))->with('classGroup:id,name'),
            'guardians' => fn ($guardians) => $guardians->where(fn ($g) => $g->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                ->orderByDesc('is_primary')->orderByDesc('is_legal_guardian')->with('person:id,full_name,social_name,phone,mobile'),
        ])->orderBy('registration_number')->limit(self::LIMIT)->get();

        return $students->map(function (Student $student) use ($context): array {
            $person = $student->person;
            /** @var ?StudentGuardian $guardian */
            $guardian = $student->guardians->first();
            $row = [
                $student->registration_number, $person->social_name ?: $person->full_name, $person->birth_date, $person->birth_date?->age,
                $student->dojoHistory->pluck('dojo.name')->filter()->unique()->implode(', '),
                $student->martialArts->pluck('martialArt.name')->filter()->implode(', '),
                $student->martialArts->map(fn ($art) => $art->currentRank?->display_name)->filter()->implode(', '),
                $student->enrollments->pluck('classGroup.name')->filter()->unique()->sort()->implode(', '),
                self::STATUS_LABELS[$student->status] ?? ucfirst((string) $student->status), $student->entry_date,
                $person->cpf ? substr($person->cpf, 0, 3).'.'.substr($person->cpf, 3, 3).'.'.substr($person->cpf, 6, 3).'-'.substr($person->cpf, 9) : null, $person->email,
                $person->mobile ?: $person->phone,
                $guardian ? ($guardian->person->social_name ?: $guardian->person->full_name) : null,
                $guardian ? ($guardian->person->mobile ?: $guardian->person->phone) : null,
            ];
            if ($context->isTeacher()) {
                unset($row[10], $row[11]);
            }

            return array_values($row);
        });
    }

    public function export(ReportContext $context, array $filters, string $scopeText): StreamedResponse
    {
        $rows = $this->rows($context, $filters);
        $report = new XlsxReport;
        $report->sheet('Alunos', 'Lista de alunos', $context->association->trade_name.' · '.$scopeText.' · '.$rows->count().' aluno(s)',
            'Gerado em '.now()->format('d/m/Y H:i').' por '.($context->user->name).' ('.$context->label().')', $this->columns($context), $rows);
        $this->audit->record('report.exported', 'exported', $context->association, null,
            ['report' => 'students', 'level' => $context->level, 'filters' => array_filter($filters), 'rows' => $rows->count()],
            ['association_id' => $context->association->id, 'dojo_id' => $context->dojo?->id]);

        return $report->download('lista-de-alunos-'.now()->format('Y-m-d').'.xlsx');
    }
}
