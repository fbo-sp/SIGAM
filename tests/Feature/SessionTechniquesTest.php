<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\MartialArt;
use App\Models\TechniqueItem;
use App\Services\Progression\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class SessionTechniquesTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_teacher_marks_groups_and_techniques_and_unmarking_keeps_the_record(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $session = $this->classSession($group, '2026-10-15');
        $nage = TechniqueItem::query()->where('name', 'Nage-waza')->sole();
        $url = route('operation.session.techniques', [$this->dojo, $session]);

        $this->actingAs($user)->get(route('operation.session', [$this->dojo, $session]))->assertOk()->assertSee('Técnicas trabalhadas')->assertSee('Marcar o grupo inteiro')->assertSee('id="tecnica-'.$this->items['O-goshi']->id.'"', false);
        $this->post($url, ['techniques' => [$nage->id, $this->items['O-goshi']->id]])->assertSessionHasNoErrors()->assertSessionHas('status', 'Técnicas trabalhadas salvas.');
        $this->post($url, ['techniques' => [$nage->id, $this->items['O-goshi']->id]])->assertSessionHas('status', 'Técnicas sem alteração.');
        $this->assertSame(2, ClassSessionTechnique::query()->whereNull('removed_at')->count());

        $this->post($url, ['techniques' => [$nage->id]])->assertSessionHasNoErrors();
        $removed = ClassSessionTechnique::query()->where('technique_item_id', $this->items['O-goshi']->id)->sole();
        $this->assertNotNull($removed->removed_at);
        $this->assertSame($user->id, $removed->removed_by_user_id);
        $this->post($url, ['techniques' => [$nage->id, $this->items['O-goshi']->id]]);
        $this->assertNull($removed->fresh()->removed_at);
        $this->assertSame(2, ClassSessionTechnique::query()->count());
        $this->assertDatabaseHas('audit_logs', ['association_id' => $this->association->id, 'event_type' => 'class_session.techniques_saved']);

        $karate = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Karatê']);
        $foreign = TechniqueItem::query()->create(['association_id' => $this->association->id, 'martial_art_id' => $karate->id, 'kind' => 'group', 'knowledge_type' => 'practical', 'name' => 'Kihon', 'status' => 'active']);
        $this->post($url, ['techniques' => [$foreign->id]])->assertSessionHasErrors('techniques');
    }

    public function test_marking_follows_the_attendance_window_and_assignment(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        [$other] = $this->teacher('Professor Bruno');
        $group = $this->group($teacher);
        $future = $this->classSession($group, '2026-10-16');
        $closed = $this->classSession($group, '2026-10-14', 'closed');
        $today = $this->classSession($group, '2026-10-15');
        $payload = ['techniques' => [$this->items['O-goshi']->id]];

        $this->actingAs($user)->post(route('operation.session.techniques', [$this->dojo, $future]), $payload)->assertForbidden();
        $this->post(route('operation.session.techniques', [$this->dojo, $closed]), $payload)->assertForbidden();
        $this->get(route('operation.session', [$this->dojo, $future]))->assertOk()->assertDontSee('Salvar técnicas');
        $this->actingAs($other)->post(route('operation.session.techniques', [$this->dojo, $today]), $payload)->assertNotFound();
        $this->assertSame(0, ClassSessionTechnique::query()->count());
    }

    public function test_only_present_students_count_the_session_as_worked(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $present = $this->student('A-1');
        $absent = $this->student('A-2');
        $this->enroll($group, $present);
        $this->enroll($group, $absent);
        $first = $this->classSession($group, '2026-10-13');
        $second = $this->classSession($group, '2026-10-15');
        foreach ([[$first, $present, 'present'], [$first, $absent, 'absent'], [$second, $present, 'makeup'], [$second, $absent, 'absent']] as [$session, $student, $status]) {
            AttendanceRecord::query()->create(['class_session_id' => $session->id, 'student_id' => $student->id, 'participation_type' => 'regular', 'status' => $status, 'recorded_at' => now(), 'recorded_by_user_id' => $user->id]);
        }
        $this->actingAs($user);
        $this->post(route('operation.session.techniques', [$this->dojo, $first]), ['techniques' => [$this->items['O-goshi']->id, $this->items['kano']->id]])->assertSessionHasNoErrors();
        $this->post(route('operation.session.techniques', [$this->dojo, $second]), ['techniques' => [$this->items['O-goshi']->id]])->assertSessionHasNoErrors();

        $service = app(StudentProgressService::class);
        $worked = $service->worked($this->association, $present, $this->art, today()->subDays(90), today());
        $this->assertSame(2, $worked['sessions']);
        $this->assertSame([['label' => 'Nage-waza', 'sessions' => 2]], $worked['axes']['practical']);
        $this->assertSame([['label' => 'História', 'sessions' => 1]], $worked['axes']['theoretical']);
        $none = $service->worked($this->association, $absent, $this->art, today()->subDays(90), today());
        $this->assertSame(0, $none['sessions']);
        $this->assertTrue($none['axes']->isEmpty());

        $this->get(route('operation.progression.show', [$this->dojo, $present]))->assertOk()->assertSee('2 de 2 aula(s)');
    }

    private function classSession(ClassGroup $group, string $date, string $state = 'open'): ClassSession
    {
        return ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => $state]);
    }
}
