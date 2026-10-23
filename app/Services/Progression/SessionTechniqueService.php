<?php

namespace App\Services\Progression;

use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Técnicas trabalhadas na aula (requisito D4 e premissa 4). Mesma permissão e mesma janela da chamada:
 * só no dia da aula ou depois, com a chamada aberta. Desmarcar não apaga o registro.
 */
class SessionTechniqueService
{
    /** Situações de presença em que o aluno estava na aula. */
    public const PRESENT_STATUSES = ['present', 'makeup', 'trial'];

    public function __construct(private readonly OperationalScope $scope, private readonly AuditService $audit) {}

    public function canMark(User $user, Dojo $dojo, ClassSession $session): bool
    {
        return $session->attendance_state === 'open' && ! in_array($session->status, ['cancelled', 'rescheduled'], true)
            && ! $session->isBeforeAttendanceDay()
            && $this->scope->sessions($user, $dojo, 'attendance.manage')->whereKey($session->id)->exists();
    }

    /** @return list<int> */
    public function marked(ClassSession $session): array
    {
        return ClassSessionTechnique::query()->where('class_session_id', $session->id)->whereNull('removed_at')->pluck('technique_item_id')->all();
    }

    /** @param  list<int|string>  $itemIds  seleção completa da aula */
    public function sync(User $user, Dojo $dojo, ClassSession $session, array $itemIds): array
    {
        abort_unless($this->canMark($user, $dojo, $session), 403);
        $session->loadMissing('classGroup');
        $itemIds = collect($itemIds)->map(fn ($id) => (int) $id)->unique()->values();
        $valid = TechniqueItem::query()->where('association_id', $dojo->association_id)->where('martial_art_id', $session->classGroup->martial_art_id)
            ->where('status', 'active')->whereKey($itemIds->all())->pluck('id');
        if ($valid->count() !== $itemIds->count()) {
            throw ValidationException::withMessages(['techniques' => 'Escolha técnicas ativas do catálogo da modalidade da turma.']);
        }

        return DB::transaction(function () use ($user, $dojo, $session, $itemIds): array {
            ClassSession::query()->whereKey($session->id)->lockForUpdate()->first();
            $existing = ClassSessionTechnique::query()->where('class_session_id', $session->id)->get()->keyBy('technique_item_id');
            $added = 0;
            $removed = 0;
            foreach ($itemIds as $itemId) {
                $row = $existing->get($itemId);
                if ($row === null) {
                    ClassSessionTechnique::query()->create(['association_id' => $dojo->association_id, 'class_session_id' => $session->id, 'technique_item_id' => $itemId, 'marked_by_user_id' => $user->id]);
                    $added++;
                } elseif ($row->removed_at !== null) {
                    $row->update(['removed_at' => null, 'removed_by_user_id' => null, 'marked_by_user_id' => $user->id]);
                    $added++;
                }
            }
            foreach ($existing->whereNull('removed_at')->keys()->diff($itemIds) as $itemId) {
                $existing[$itemId]->update(['removed_at' => now(), 'removed_by_user_id' => $user->id]);
                $removed++;
            }
            if ($added + $removed > 0) {
                $this->audit->record('class_session.techniques_saved', 'updated', $session, null, ['added' => $added, 'removed' => $removed, 'total' => $itemIds->count()], ['association_id' => $dojo->association_id]);
            }

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /**
     * Aulas da modalidade em que o aluno estava presente no período e as técnicas marcadas em cada uma.
     *
     * @return array{sessions: int, techniques: Collection<int, list<int>>} técnicas: item_id => ids das aulas
     */
    public function workedByStudent(Student $student, MartialArt $martialArt, CarbonInterface $from, CarbonInterface $until): array
    {
        $sessions = DB::table('attendance')->join('class_sessions', 'class_sessions.id', '=', 'attendance.class_session_id')
            ->join('class_groups', 'class_groups.id', '=', 'class_sessions.class_group_id')
            ->where('attendance.student_id', $student->id)->whereIn('attendance.status', self::PRESENT_STATUSES)
            ->where('class_groups.association_id', $student->association_id)->where('class_groups.martial_art_id', $martialArt->id)
            ->where('class_sessions.session_date', '>=', $from->toDateString())->where('class_sessions.session_date', '<', $until->copy()->addDay()->toDateString())
            ->distinct()->pluck('class_sessions.id');
        $techniques = ClassSessionTechnique::query()->whereIn('class_session_id', $sessions)->whereNull('removed_at')
            ->get(['class_session_id', 'technique_item_id'])->groupBy('technique_item_id')
            ->map(fn (Collection $rows) => $rows->pluck('class_session_id')->unique()->values()->all());

        return ['sessions' => $sessions->count(), 'techniques' => $techniques];
    }
}
