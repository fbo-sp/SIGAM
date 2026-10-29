<?php

namespace App\Services\Attendance;

use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\ClassSessionStaff;
use App\Models\DojoTeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Quem deu a aula e quem auxiliou (decisão de Fabio em 28/10/2026, para a frequência detalhada). As opções são o
 * principal e os auxiliares da turma na data e os professores vinculados ao dojo da turma, para cobrir substituições.
 */
class SessionStaffService
{
    /** @return Collection<int, TeacherProfile> professores que podem constar na aula, principal da turma primeiro */
    public function options(ClassSession $session): Collection
    {
        $session->loadMissing('classGroup');
        $group = $session->classGroup;
        $date = $session->session_date->toDateString();
        $ids = collect([$group->main_teacher_id, $session->planned_teacher_id])
            ->merge(ClassGroupTeacher::query()->where('class_group_id', $group->id)->current($date)->pluck('teacher_profile_id'))
            ->merge(DojoTeacherAssignment::query()->where('association_id', $group->association_id)->where('dojo_id', $group->dojo_id)->whereNull('revoked_at')
                ->whereDate('start_date', '<=', $date)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date))->pluck('teacher_profile_id'))
            ->merge($session->staff()->pluck('teacher_profile_id'))
            ->filter()->unique();

        return TeacherProfile::query()->whereIn('id', $ids)->with('person:id,full_name,social_name')->get()
            ->sortBy(fn (TeacherProfile $teacher) => [$teacher->id === $group->main_teacher_id ? 0 : 1, mb_strtolower($teacher->person->social_name ?: $teacher->person->full_name)])->values();
    }

    /** @return array{lead: ?int, assistants: list<int>} o que já foi registrado ou a sugestão para a tela */
    public function defaults(ClassSession $session, ?User $user = null): array
    {
        $saved = $session->staff()->get(['teacher_profile_id', 'role']);
        if ($saved->isNotEmpty()) {
            return ['lead' => $saved->firstWhere('role', 'lead')?->teacher_profile_id, 'assistants' => $saved->where('role', 'assistant')->pluck('teacher_profile_id')->all()];
        }
        $options = $this->options($session)->pluck('id');
        $mine = $user?->person_id ? TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id') : null;
        $lead = collect([$mine, $session->planned_teacher_id, $session->classGroup->main_teacher_id])->first(fn ($id) => $id && $options->contains($id));
        $assistants = ClassGroupTeacher::query()->where('class_group_id', $session->class_group_id)->where('role_type', 'assistant')
            ->current($session->session_date->toDateString())->pluck('teacher_profile_id')->reject(fn ($id) => $id === $lead)->values()->all();

        return ['lead' => $lead, 'assistants' => $assistants];
    }

    /**
     * Grava o professor e os auxiliares; ao finalizar sem escolha, grava a sugestão (principal previsto e auxiliares da turma).
     *
     * @param  array{lead?: mixed, assistants?: mixed}|null  $staff
     * @return array{lead: ?int, assistants: list<int>}|null o que foi gravado, para a auditoria
     */
    public function store(ClassSession $session, User $user, ?array $staff, bool $closing): ?array
    {
        $options = $this->options($session)->pluck('id');
        $lead = isset($staff['lead']) && $staff['lead'] !== '' ? (int) $staff['lead'] : null;
        if ($lead === null) {
            // Sem escolha na tela (importação, envio antigo): ao finalizar, grava a sugestão se ainda não há registro.
            if (! $closing || $session->staff()->exists()) {
                return null;
            }
            $staff = $this->defaults($session, $user);
            $lead = $staff['lead'];
            if ($lead === null) {
                return null;
            }
        }
        $assistants = collect($staff['assistants'] ?? [])->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $lead)->unique()->values();
        if (! $options->contains($lead) || $assistants->diff($options)->isNotEmpty()) {
            throw ValidationException::withMessages(['staff_lead' => 'Escolha professores da turma ou do dojo desta aula.']);
        }
        $wanted = $assistants->mapWithKeys(fn ($id) => [$id => 'assistant'])->put($lead, 'lead');
        $session->staff()->whereNotIn('teacher_profile_id', $wanted->keys())->delete();
        foreach ($wanted as $teacherId => $role) {
            ClassSessionStaff::query()->updateOrCreate(['class_session_id' => $session->id, 'teacher_profile_id' => $teacherId], ['role' => $role, 'recorded_by_user_id' => $user->id]);
        }

        return ['lead' => $lead, 'assistants' => $assistants->all()];
    }
}
