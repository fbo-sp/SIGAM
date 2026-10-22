<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\TechniqueAssessment;
use App\Models\User;
use App\Services\Progression\ProgressionPanelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class ProgressionPanelTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_teacher_sees_own_classes_and_manager_sees_the_dojo(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        [, $bruno] = $this->teacher('Professor Bruno');
        $groupA = $this->group($ana);
        $groupB = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma B', 'main_teacher_id' => $bruno->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        $ready = $this->student('A-1');
        $behind = $this->student('A-2');
        $this->enroll($groupA, $ready);
        $this->enroll($groupA, $behind);
        $this->enroll($groupB, $this->student('B-1'));
        foreach (['kano' => 2, 'shido' => 2, 'O-goshi' => 3, 'Kesa-gatame' => 2, 'Nage-no-kata' => 2] as $key => $level) {
            TechniqueAssessment::query()->create(['association_id' => $this->association->id, 'student_id' => $ready->id, 'martial_art_id' => $this->art->id, 'technique_item_id' => $this->items[$key]->id, 'level' => $level, 'teacher_profile_id' => $ana->id, 'dojo_id' => $this->dojo->id, 'assessed_by_user_id' => $anaUser->id, 'assessed_on' => '2026-10-10']);
        }
        $session = ClassSession::query()->create(['class_group_id' => $groupA->id, 'session_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'closed']);
        ClassSession::query()->create(['class_group_id' => $groupA->id, 'session_date' => '2026-10-20', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        ClassSessionTechnique::query()->create(['association_id' => $this->association->id, 'class_session_id' => $session->id, 'technique_item_id' => $this->items['O-goshi']->id, 'marked_by_user_id' => $anaUser->id]);

        $panel = app(ProgressionPanelService::class)->panel($this->association, $this->art, collect([$groupA]), today()->subDays(90), today());
        $this->assertSame(2, $panel['students']);
        $this->assertSame(1, $panel['sessions']);
        $nage = collect($panel['axes']['practical'])->firstWhere('label', 'Nage-waza');
        $this->assertSame([1, 2, 1], [$nage['students_below'], $nage['students'], $nage['sessions_worked']]);
        $this->assertSame(['A-2', 'A-1'], $panel['ranking']->map(fn ($row) => $row['student']->registration_number)->all());
        $this->assertSame([2, 3], [$panel['ranking'][0]['theoretical'], $panel['ranking'][0]['practical']]);

        $this->actingAs($anaUser)->get(route('operation.home', $this->dojo))->assertSee('Acompanhamento técnico');
        $this->get(route('operation.progression.panel', $this->dojo))->assertOk()->assertSee('Turma A')->assertDontSee('Turma B')->assertSee('Precisa x trabalhado')->assertSee('A-2');
        $this->get(route('operation.progression.panel', [$this->dojo, 'turma' => $groupB->id]))->assertNotFound();
        $this->get(route('operation.progression.panel', [$this->dojo, 'de' => '2026-10-15', 'ate' => '2026-10-01']))->assertSessionHasErrors('ate');

        $manager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($manager, $this->role('dojo', ['dojo.access', 'student.view', 'progression.report']));
        $this->actingAs($manager)->get(route('operation.progression.panel', $this->dojo))->assertOk()->assertSee('Turma B')->assertSee('B-1');

        $plain = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($plain, $this->role('dojo', ['dojo.access', 'student.view']));
        $this->actingAs($plain)->get(route('operation.progression.panel', $this->dojo))->assertForbidden();
    }

    public function test_association_compares_dojos_and_teachers(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->enroll($this->group($ana), $this->student('A-1'));
        TechniqueAssessment::query()->create(['association_id' => $this->association->id, 'student_id' => 1, 'martial_art_id' => $this->art->id, 'technique_item_id' => $this->items['kano']->id, 'level' => 2, 'teacher_profile_id' => $ana->id, 'dojo_id' => $this->dojo->id, 'assessed_by_user_id' => $anaUser->id, 'assessed_on' => '2026-10-10']);

        $comparison = app(ProgressionPanelService::class)->comparison($this->association, $this->art, today()->subDays(90), today());
        $this->assertSame('Dojo Norte', $comparison['dojos'][0]['label']);
        $this->assertSame(1.0, $comparison['dojos'][0]['missing_theoretical']);
        $this->assertSame(1, $comparison['teachers'][0]['assessments']);

        $this->actingAs($this->associationUser(['progression.report']))->get(route('associations.progression.report', $this->association))
            ->assertOk()->assertSee('Por dojo')->assertSee('Dojo Norte')->assertSee('Professora Ana');
        $this->actingAs($this->associationUser(['student.view']))->get(route('associations.progression.report', $this->association))->assertForbidden();
    }
}
