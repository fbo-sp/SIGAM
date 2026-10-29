<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AttendanceYesNoTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_roll_call_uses_yes_no_starting_blank_and_closes_only_with_everyone_marked(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $ana = $this->student('A-1');
        $bia = $this->student('A-2');
        $this->enroll($group, $ana);
        $this->enroll($group, $bia);
        $page = route('operation.session', [$this->dojo, $session]);
        $save = route('operation.attendance', [$this->dojo, $session]);

        $this->actingAs($user)->get($page)->assertOk()->assertSee('0 de 2')->assertSee('Todos SIM')->assertSee('value="present"', false)
            ->assertSee('value="absent"', false)->assertDontSee('<select name="records', false)->assertDontSee('checked', false);

        // Só a Ana foi marcada: a Bia fica em branco, não é gravada e a chamada não fecha.
        $this->post($save, ['records' => [['student_id' => $ana->id, 'status' => 'present'], ['student_id' => $bia->id]], 'close_attendance' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(['present'], $session->records()->pluck('status')->all());
        $this->get($page)->assertSee('1 de 2');
        $this->post($save, ['records' => [['student_id' => $ana->id, 'status' => 'present'], ['student_id' => $bia->id]], 'close_attendance' => '1'])
            ->assertSessionHasErrors(['attendance' => 'Marque SIM ou NÃO para todos os alunos antes de finalizar a chamada.']);
        $this->assertSame('open', $session->fresh()->attendance_state);

        $this->post($save, ['records' => [['student_id' => $ana->id, 'status' => 'excused_absence'], ['student_id' => $bia->id, 'status' => 'absent']]])->assertSessionHasErrors('records.0.status');
        $this->post($save, ['records' => [['student_id' => $ana->id, 'status' => 'present'], ['student_id' => $bia->id, 'status' => 'absent']], 'close_attendance' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $session->fresh()->attendance_state);
        $this->assertSame('absent', $session->records()->where('student_id', $bia->id)->value('status'));
    }

    public function test_confirming_the_same_answer_keeps_old_equivalent_statuses_and_association_roll_uses_yes_no(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        [$ana, $bia, $caio] = [$this->student('A-1'), $this->student('A-2'), $this->student('A-3')];
        foreach ([[$ana, 'excused_absence'], [$bia, 'makeup'], [$caio, 'excused_absence']] as [$student, $status]) {
            $this->enroll($group, $student);
            $session->records()->create(['student_id' => $student->id, 'participation_type' => 'regular', 'status' => $status, 'recorded_at' => now(), 'recorded_by_user_id' => $user->id]);
        }

        $this->actingAs($user)->get(route('operation.session', [$this->dojo, $session]))->assertOk()->assertSee('3 de 3');
        $this->post(route('operation.attendance', [$this->dojo, $session]), ['records' => [
            ['student_id' => $ana->id, 'status' => 'absent'], ['student_id' => $bia->id, 'status' => 'present'], ['student_id' => $caio->id, 'status' => 'present'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['excused_absence', 'makeup', 'present'], array_map(fn ($s) => $session->records()->where('student_id', $s->id)->value('status'), [$ana, $bia, $caio]));

        $manager = $this->associationUser(['session.view', 'attendance.manage']);
        $this->actingAs($manager)->get(route('associations.attendance.show', [$this->association, $session]))->assertOk()
            ->assertSee('Todos SIM')->assertSee('class="yes-no-yes"', false)->assertDontSee('<select name="records', false);
    }
}
