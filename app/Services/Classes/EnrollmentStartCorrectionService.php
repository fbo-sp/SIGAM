<?php

namespace App\Services\Classes;

use App\Models\Association;
use App\Models\AttendanceRecord;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentDojoHistory;
use App\Models\StudentMartialArt;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige a data de início de uma matrícula em turma cadastrada errada. Só o administrador do sistema e o gestor
 * da associação fazem isso. As chamadas abertas montam a lista pela matrícula, então já passam a mostrar o aluno;
 * as fechadas no novo período são devolvidas para o gestor decidir se reabre.
 */
class EnrollmentStartCorrectionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{corrected_start: string, correction_reason: string}  $data
     * @return array{enrollment: ClassEnrollment, adjusted: list<string>, closed_sessions: Collection<int, ClassSession>}
     */
    public function correct(Association $association, ClassEnrollment $enrollment, array $data): array
    {
        return DB::transaction(function () use ($association, $enrollment, $data): array {
            $locked = ClassEnrollment::query()->with('classGroup')->lockForUpdate()->findOrFail($enrollment->id);
            $group = $locked->classGroup;
            abort_unless($group->association_id === $association->id, 404);
            if (! in_array($locked->status, ['active', 'ended'], true)) {
                throw ValidationException::withMessages(['corrected_start' => 'Esta matrícula não pode ser corrigida.']);
            }
            $student = Student::query()->lockForUpdate()->findOrFail($locked->student_id);
            $old = $locked->start_date->copy()->startOfDay();
            $new = Carbon::parse($data['corrected_start'])->startOfDay();
            if ($new->equalTo($old)) {
                throw ValidationException::withMessages(['corrected_start' => 'A matrícula já começa nessa data.']);
            }
            if ($new->gt(today()) || ($locked->end_date !== null && $new->gt($locked->end_date))) {
                throw ValidationException::withMessages(['corrected_start' => 'Use uma data até hoje e antes do fim da matrícula.']);
            }
            if ($new->lt($group->start_date)) {
                throw ValidationException::withMessages(['corrected_start' => 'A turma começa em '.$group->start_date->format('d/m/Y').'. Para antecipar, use "Gerar aulas passadas" na turma.']);
            }
            $overlap = ClassEnrollment::query()->where('class_group_id', $group->id)->where('student_id', $student->id)->whereKeyNot($locked->id)
                ->whereIn('status', ['active', 'ended'])->whereDate('start_date', '<=', $locked->end_date ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $new))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['corrected_start' => 'O aluno tem outra matrícula nesta turma nesse período.']);
            }
            if ($new->gt($old)) {
                $earlier = AttendanceRecord::query()->where('student_id', $student->id)->where('participation_type', 'regular')
                    ->whereHas('session', fn ($query) => $query->where('class_group_id', $group->id)
                        ->whereDate('session_date', '>=', $old)->whereDate('session_date', '<', $new))->count();
                if ($earlier > 0) {
                    throw ValidationException::withMessages(['corrected_start' => "O aluno tem {$earlier} presença(s) registrada(s) antes dessa data. Presença concluída não é apagada."]);
                }
            }

            $adjusted = $new->lt($old) ? $this->moveLinksBack($student, $group->dojo_id, $group->martial_art_id, $old, $new) : [];
            $locked->update(['start_date' => $new->toDateString()]);
            $this->audit->record('class_enrollment.start_corrected', 'updated', $locked, ['start_date' => $old->toDateString()], [
                'start_date' => $new->toDateString(), 'adjusted' => $adjusted,
            ], ['association_id' => $association->id, 'reason' => $data['correction_reason']]);

            $closed = $new->lt($old) ? ClassSession::query()->where('class_group_id', $group->id)->where('attendance_state', 'closed')
                ->whereNotIn('status', ['cancelled', 'rescheduled'])->whereDate('session_date', '>=', $new)->whereDate('session_date', '<', $old)
                ->whereDoesntHave('records', fn ($query) => $query->where('student_id', $student->id))
                ->orderBy('session_date')->orderBy('start_time')->get() : collect();

            return ['enrollment' => $locked->fresh(), 'adjusted' => $adjusted, 'closed_sessions' => $closed];
        });
    }

    /**
     * Mensagem e lista de aulas fechadas no novo período, com link para a chamada no dojo da turma.
     *
     * @param  array{enrollment: ClassEnrollment, adjusted: list<string>, closed_sessions: Collection<int, ClassSession>}  $result
     * @return array{status: string, closed_sessions: list<array{label: string, url: string}>}
     */
    public static function flash(array $result): array
    {
        $labels = ['dojo' => 'o vínculo com o dojo', 'modalidade' => 'a modalidade', 'entrada' => 'a data de entrada'];
        $adjusted = array_map(fn (string $key) => $labels[$key], $result['adjusted']);
        $status = 'Início da matrícula corrigido para '.$result['enrollment']->start_date->format('d/m/Y').'.';
        if ($adjusted !== []) {
            $last = array_pop($adjusted);
            $status .= ' Também recuaram '.($adjusted === [] ? $last : implode(', ', $adjusted).' e '.$last).'.';
        }
        $status .= ' As chamadas abertas já mostram o aluno.';
        $group = $result['enrollment']->classGroup;

        return ['status' => $status, 'closed_sessions' => $result['closed_sessions']->map(fn (ClassSession $session) => [
            'label' => $session->session_date->format('d/m/Y').' · '.substr((string) $session->start_time, 0, 5),
            'url' => route('operation.session', [$group->dojo_id, $session->id]),
        ])->values()->all()];
    }

    /**
     * A matrícula só vale com vínculo no dojo e na modalidade na data. No cadastro direto todas essas datas nascem
     * iguais, então o vínculo que cobria o início antigo recua junto, sem invadir outro período do mesmo dojo ou modalidade.
     *
     * @return list<string>
     */
    private function moveLinksBack(Student $student, int $dojoId, int $martialArtId, Carbon $old, Carbon $new): array
    {
        $adjusted = [];
        $dojoLink = StudentDojoHistory::query()->where('student_id', $student->id)->where('dojo_id', $dojoId)->whereDate('start_date', '<=', $old)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $old))->orderByDesc('start_date')->lockForUpdate()->first();
        if ($dojoLink === null) {
            throw ValidationException::withMessages(['corrected_start' => 'O aluno não tem vínculo com o dojo da turma na data atual da matrícula. Corrija o vínculo antes.']);
        }
        if ($dojoLink->start_date->gt($new)) {
            $blocked = StudentDojoHistory::query()->where('student_id', $student->id)->where('dojo_id', $dojoId)->whereKeyNot($dojoLink->id)
                ->whereDate('start_date', '<', $dojoLink->start_date)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $new))->exists();
            if ($blocked) {
                throw ValidationException::withMessages(['corrected_start' => 'Antes dessa data o aluno tem outro período no mesmo dojo. Escolha uma data dentro do vínculo atual.']);
            }
            $adjusted[] = 'dojo';
            $dojoLink->update(['start_date' => $new->toDateString()]);
        }

        $art = StudentMartialArt::query()->where('student_id', $student->id)->where('martial_art_id', $martialArtId)
            ->whereDate('start_date', '<=', $old)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $old))
            ->orderByDesc('start_date')->lockForUpdate()->first();
        if ($art === null) {
            throw ValidationException::withMessages(['corrected_start' => 'O aluno não pratica a modalidade da turma na data atual da matrícula. Corrija a modalidade antes.']);
        }
        if ($art->start_date->gt($new)) {
            $blocked = StudentMartialArt::query()->where('student_id', $student->id)->where('martial_art_id', $martialArtId)->whereKeyNot($art->id)
                ->whereDate('start_date', '<', $art->start_date)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $new))->exists();
            if ($blocked) {
                throw ValidationException::withMessages(['corrected_start' => 'Antes dessa data o aluno tem outro período na mesma modalidade. Escolha uma data dentro do período atual.']);
            }
            $oldArtStart = $art->start_date->toDateString();
            $art->rankHistory()->where('source_type', 'initial_registration')->whereDate('achieved_at', $oldArtStart)->update(['achieved_at' => $new->toDateString()]);
            $art->update(['start_date' => $new->toDateString()]);
            $adjusted[] = 'modalidade';
        }

        if ($student->entry_date !== null && $student->entry_date->gt($new)) {
            $student->update(['entry_date' => $new->toDateString()]);
            $adjusted[] = 'entrada';
        }

        return $adjusted;
    }
}
