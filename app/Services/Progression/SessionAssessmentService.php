<?php

namespace App\Services\Progression;

use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\Student;
use App\Models\TechniqueItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Avaliar técnicas desta aula": depois da chamada encerrada, o professor da turma avalia os alunos presentes
 * nas técnicas marcadas na aula que fazem parte do currículo da próxima graduação de cada um. A data é a da aula.
 */
class SessionAssessmentService
{
    public function __construct(
        private readonly AssessmentService $assessments,
        private readonly StudentProgressService $progress,
        private readonly SessionTechniqueService $techniques,
    ) {}

    public function available(User $user, Dojo $dojo, ClassSession $session): bool
    {
        $session->loadMissing('classGroup.martialArt');

        return $session->attendance_state === 'closed' && ! in_array($session->status, ['cancelled', 'rescheduled'], true)
            && $this->techniques->marked($session) !== [] && $this->assessments->teachesClass($user, $dojo, $session->classGroup);
    }

    /**
     * Técnicas da aula (o grupo marcado conta para os itens abaixo dele) com os alunos presentes que o professor avalia
     * e que têm o item no currículo da próxima graduação.
     *
     * @return Collection<int, array{item: TechniqueItem, rows: Collection<int, array{student: Student, current: ?int, required: int, status: ?string}>}>
     */
    public function sheet(User $user, Dojo $dojo, ClassSession $session): Collection
    {
        abort_unless($this->available($user, $dojo, $session), 403);
        $art = $session->classGroup->martialArt;
        $marked = $this->expand($dojo, $session);
        $students = Student::query()->whereIn('id', $session->records()->whereIn('status', SessionTechniqueService::PRESENT_STATUSES)->pluck('student_id'))
            ->with('person')->get()->filter(fn (Student $student) => $this->assessments->assessorTeacherId($user, $dojo, $student, $art) !== null)
            ->sortBy(fn (Student $student) => mb_strtolower($student->person->social_name ?: $student->person->full_name));
        $byItem = [];
        foreach ($students as $student) {
            $snapshot = $this->progress->snapshot($dojo->association, $student, $art, details: false);
            foreach ($snapshot['rows']->flatten(1) as $row) {
                if (isset($marked[$row['item']->id])) {
                    $byItem[$row['item']->id]['item'] = $row['item'];
                    $byItem[$row['item']->id]['rows'][] = ['student' => $student, 'current' => $row['current'], 'required' => $row['level'], 'missing' => $row['missing']];
                }
            }
        }

        return collect($byItem)->map(fn (array $entry) => ['item' => $entry['item'], 'rows' => collect($entry['rows'])])
            ->sortBy(fn (array $entry) => $marked[$entry['item']->id])->values();
    }

    /**
     * @param  array<int|string, array<int|string, int|string|null>>  $levels  student_id => item_id => nível
     * @param  array<int|string, array<int|string, string|null>>  $tips  student_id => item_id => dica
     * @return array{items: int, students: int}
     */
    public function record(User $user, Dojo $dojo, ClassSession $session, array $levels, array $tips): array
    {
        $sheet = $this->sheet($user, $dojo, $session);
        $allowed = [];
        foreach ($sheet as $entry) {
            foreach ($entry['rows'] as $row) {
                $allowed[$row['student']->id][$entry['item']->id] = true;
            }
        }
        $students = $sheet->flatMap(fn (array $entry) => $entry['rows']->pluck('student'))->keyBy('id');
        foreach ($levels as $studentId => $items) {
            foreach ($items as $itemId => $level) {
                if ($level !== null && $level !== '' && ! isset($allowed[(int) $studentId][(int) $itemId])) {
                    throw ValidationException::withMessages(['levels' => 'Avalie só os alunos presentes nas técnicas desta aula.']);
                }
            }
        }

        return DB::transaction(function () use ($user, $dojo, $session, $levels, $tips, $students): array {
            $items = 0;
            $touched = 0;
            foreach ($levels as $studentId => $studentLevels) {
                $saved = $this->assessments->record($user, $dojo, $students[(int) $studentId], $session->classGroup->martialArt,
                    $studentLevels, Carbon::parse($session->session_date), null, $tips[$studentId] ?? []);
                $items += $saved;
                $touched += $saved > 0 ? 1 : 0;
            }

            return ['items' => $items, 'students' => $touched];
        });
    }

    /** @return array<int, int> item_id => ordem no catálogo, para os itens marcados e os que estão abaixo deles */
    private function expand(Dojo $dojo, ClassSession $session): array
    {
        $items = TechniqueItem::query()->where('association_id', $dojo->association_id)->where('martial_art_id', $session->classGroup->martial_art_id)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id']);
        $children = $items->groupBy('parent_id');
        $order = $items->pluck('id')->flip();
        $result = [];
        $stack = $this->techniques->marked($session);
        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = $order[$id] ?? PHP_INT_MAX;
            array_push($stack, ...$children->get($id, collect())->pluck('id')->all());
        }

        return $result;
    }
}
