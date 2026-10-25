<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\Dojo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AttendancePhotoTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_roll_call_shows_photo_or_initials_of_each_student(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $withPhoto = $this->student('A-1');
        $withPhoto->person->forceFill(['photo_path' => 'photos/'.$withPhoto->person_id.'/teste'])->save();
        $this->enroll($group, $withPhoto);
        $this->enroll($group, $this->student('A-2'));

        $this->actingAs($user)->get(route('operation.session', [$this->dojo, $session]))->assertOk()
            ->assertSee(e($withPhoto->person->fresh()->photoUrl()), false)
            ->assertSee('class="person-photo person-photo-thumb"  aria-hidden="true"', false);
    }

    public function test_association_session_list_filters_by_dojo_class_and_teacher_and_roll_shows_photos(): void
    {
        [, $ana] = $this->teacher('Professora Ana');
        [, $bia] = $this->teacher('Professora Bia');
        $north = $this->group($ana);
        $south = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $other = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $south->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma Sul', 'main_teacher_id' => $bia->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        ClassGroupTeacher::query()->create(['class_group_id' => $north->id, 'teacher_profile_id' => $bia->id, 'role_type' => 'assistant', 'start_date' => today()->toDateString()]);
        $today = today()->toDateString();
        $session = fn (ClassGroup $group, string $date, $teacher) => ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'planned_teacher_id' => $teacher->id, 'status' => 'planned', 'attendance_state' => 'open']);
        $northToday = $session($north, $today, $ana);
        $session($other, $today, $bia);
        $session($north, today()->subWeek()->toDateString(), $ana);
        $this->enroll($north, $this->student('A-1'));
        $manager = $this->associationUser(['session.view', 'attendance.manage']);
        $index = fn (array $query) => $this->actingAs($manager)->get(route('associations.attendance.index', [$this->association, ...$query]))->assertOk();

        $index([])->assertSee('Turma Sul')->assertDontSee(today()->subWeek()->format('d/m/Y'));
        $this->assertSame(2, substr_count($index([])->getContent(), 'class="list-row"'));
        $this->assertSame(3, substr_count($index(['date' => ''])->getContent(), 'class="list-row"'));
        $index(['dojo' => $south->id])->assertSee('Turma Sul')->assertDontSee('Turma A</strong>', false);
        $index(['turma' => $north->id])->assertSee('Turma A</strong>', false)->assertDontSee('Turma Sul</strong>', false);
        // A professora auxiliar vê a turma em que auxilia a partir do início da designação, além da própria turma.
        $this->assertSame(2, substr_count($index(['professor' => $bia->id])->getContent(), 'class="list-row"'));
        $this->assertSame(2, substr_count($index(['professor' => $bia->id, 'date' => ''])->getContent(), 'class="list-row"'));
        $this->assertSame(2, substr_count($index(['professor' => $ana->id, 'date' => ''])->getContent(), 'class="list-row"'));

        $this->get(route('associations.attendance.show', [$this->association, $northToday]))->assertOk()->assertSee('class="person-cell"><span class="person-photo', false);
    }
}
