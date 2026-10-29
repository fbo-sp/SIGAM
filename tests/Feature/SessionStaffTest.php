<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class SessionStaffTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    private function openSession(int $groupId, string $state = 'open'): ClassSession
    {
        return ClassSession::query()->create(['class_group_id' => $groupId, 'session_date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => $state]);
    }

    public function test_closing_records_who_taught_and_assistants_and_shows_them_on_the_closed_roll(): void
    {
        [, $ana] = $this->teacher('Professora Ana');
        [$brunoUser, $bruno] = $this->teacher('Bruno Auxiliar');
        [, $carla] = $this->teacher('Carla Substituta');
        $group = $this->group($ana);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $bruno->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01']);
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        $session = $this->openSession($group->id);
        $page = route('operation.session', [$this->dojo, $session]);

        // Quem faz a chamada vem sugerido como professor da aula; os três professores do dojo aparecem como opção.
        $this->actingAs($brunoUser)->get($page)->assertOk()->assertSee('Quem deu esta aula?')
            ->assertSee('<option value="'.$bruno->id.'" selected>', false)->assertSee('Professora Ana (principal da turma)')->assertSee('Carla Substituta');

        $this->post(route('operation.attendance', [$this->dojo, $session]), ['records' => [['student_id' => $student->id, 'status' => 'present']], 'close_attendance' => '1',
            'staff_lead' => $carla->id, 'staff_assistants' => [$bruno->id, $carla->id]])->assertSessionHasNoErrors();

        $this->assertSame([$bruno->id => 'assistant', $carla->id => 'lead'], $session->staff()->orderBy('teacher_profile_id')->pluck('role', 'teacher_profile_id')->all());
        $audit = AuditLog::query()->where('event_type', 'attendance.closed')->latest('id')->first();
        $this->assertSame(['lead' => $carla->id, 'assistants' => [$bruno->id]], $audit->new_values['staff']);
        $this->get($page)->assertSee('Professor da aula:')->assertSee('Carla Substituta')->assertSee('Auxiliares:')->assertSee('Bruno Auxiliar');
    }

    public function test_closing_without_choice_records_the_suggestion_and_rejects_teachers_outside_the_dojo(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $group = $this->group($ana);
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        $outsider = TeacherProfile::query()->create(['person_id' => $this->person('Fora do Dojo')->id, 'status' => 'active']);
        $session = $this->openSession($group->id);
        $save = route('operation.attendance', [$this->dojo, $session]);

        $this->actingAs($anaUser)->post($save, ['records' => [['student_id' => $student->id, 'status' => 'present']], 'close_attendance' => '1', 'staff_lead' => $outsider->id])
            ->assertSessionHasErrors('staff_lead');
        $this->assertSame('open', $session->fresh()->attendance_state);
        $this->assertSame(0, $session->staff()->count());

        $this->post($save, ['records' => [['student_id' => $student->id, 'status' => 'present']], 'close_attendance' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([$ana->id => 'lead'], $session->staff()->pluck('role', 'teacher_profile_id')->all());
    }

    public function test_old_closed_session_without_record_shows_the_planned_teacher(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $session = $this->openSession($this->group($ana)->id, 'closed');

        $this->actingAs($anaUser)->get(route('operation.session', [$this->dojo, $session]))->assertOk()
            ->assertSee('Professor previsto:')->assertSee('quem deu a aula não foi registrado');
    }
}
