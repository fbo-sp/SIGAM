<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Rank;
use App\Models\Role;
use App\Models\TechniqueAssessment;
use App\Models\User;
use App\Services\Progression\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class TechniqueAssessmentTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_main_teacher_records_only_changed_levels_and_history_is_kept(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $student = $this->student('A-1');
        $group = $this->group($teacher);
        $this->enroll($group, $student);
        ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-15', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $url = route('operation.progression.assess.store', [$this->dojo, $student, $this->art]);

        $this->actingAs($user)->get(route('operation.classes.show', [$this->dojo, $group]))->assertOk()
            ->assertSee(route('operation.progression.assess', [$this->dojo, $student, $this->art]), false);
        $this->get(route('operation.progression.assess', [$this->dojo, $student, $this->art]))->assertOk()
            ->assertSee('Avaliar A-1')->assertSee('O-goshi')->assertSee('Jigoro Kano');

        $payload = ['levels' => [$this->items['O-goshi']->id => 3, $this->items['kano']->id => 2, $this->items['shido']->id => ''], 'assessed_on' => '2026-10-15'];
        $this->post($url, $payload)->assertSessionHasNoErrors()->assertSessionHas('status', 'Avaliação registrada em 2 item(ns).');
        $this->post($url, $payload)->assertSessionHas('status', 'Nenhum nível mudou; nada foi gravado.');
        $this->assertSame(2, TechniqueAssessment::query()->count());

        $this->post($url, ['levels' => [$this->items['O-goshi']->id => 4], 'assessed_on' => '2026-10-15', 'notes' => 'Evoluiu bem'])->assertSessionHasNoErrors();
        $this->assertSame(2, TechniqueAssessment::query()->where('technique_item_id', $this->items['O-goshi']->id)->count());
        $this->assertSame($teacher->id, TechniqueAssessment::query()->latest('id')->first()->teacher_profile_id);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $this->association->id, 'event_type' => 'technique_assessment.recorded']);

        $snapshot = app(StudentProgressService::class)->snapshot($this->association, $student, $this->art);
        $this->assertSame('amarela', $snapshot['next_rank']->name);
        $this->assertSame(['Shido'], $snapshot['missing']['theoretical']->map(fn ($row) => $row['item']->name)->all());
        $this->assertSame(['Kesa-gatame', 'Nage-no-kata'], $snapshot['missing']['practical']->map(fn ($row) => $row['item']->name)->sort()->values()->all());
        $this->assertSame(['História', 'Regras'], array_column($snapshot['radar']['theoretical'], 'label'));
        $this->assertSame(['Kata', 'Katame-waza', 'Nage-waza'], collect($snapshot['radar']['practical'])->pluck('label')->sort()->values()->all());

        $this->get(route('operation.progression.show', [$this->dojo, $student]))->assertOk()
            ->assertSee('rumo a', false)->assertSee('O que falta para Faixa amarela')->assertSee('Evoluiu bem')->assertSee('<svg', false);
    }

    public function test_requirements_show_age_and_time_in_current_rank(): void
    {
        $young = $this->student('A-1', '2022-01-01', '2026-09-01');
        $snapshot = app(StudentProgressService::class)->snapshot($this->association, $young, $this->art);
        $this->assertSame([false, false], array_column($snapshot['requirements'], 'met'));

        $adult = $this->student('A-2', '2000-01-01', '2026-01-01');
        $snapshot = app(StudentProgressService::class)->snapshot($this->association, $adult, $this->art);
        $this->assertSame([true, true], array_column($snapshot['requirements'], 'met'));
    }

    public function test_only_current_main_or_assistant_teacher_of_the_student_assesses(): void
    {
        [, $main] = $this->teacher('Professora Ana');
        [$assistantUser, $assistant] = $this->teacher('Professor Bruno');
        [$outsiderUser] = $this->teacher('Professora Carla');
        [$endedUser, $ended] = $this->teacher('Professor Davi');
        $group = $this->group($main);
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $assistant->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01']);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $ended->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01', 'end_date' => '2026-10-01']);
        $payload = ['levels' => [$this->items['O-goshi']->id => 2], 'assessed_on' => '2026-10-15'];
        $url = route('operation.progression.assess.store', [$this->dojo, $student, $this->art]);

        $this->actingAs($assistantUser)->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame($assistant->id, TechniqueAssessment::query()->sole()->teacher_profile_id);

        foreach ([$outsiderUser, $endedUser] as $user) {
            $this->actingAs($user)->post($url, $payload)->assertForbidden();
            $this->get(route('operation.progression.show', [$this->dojo, $student]))->assertForbidden();
        }

        $other = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Karatê']);
        $this->actingAs($assistantUser)->post(route('operation.progression.assess.store', [$this->dojo, $student, $other]), $payload)->assertForbidden();

        $this->post($url, ['levels' => $payload['levels'], 'assessed_on' => '2026-10-16'])->assertSessionHasErrors('assessed_on');
        $this->items['O-goshi']->update(['status' => 'inactive']);
        $this->post($url, ['levels' => [$this->items['O-goshi']->id => 3], 'assessed_on' => '2026-10-15'])->assertSessionHasErrors('levels');
        $this->assertSame(1, TechniqueAssessment::query()->count());
    }

    public function test_dojo_manager_and_association_only_view(): void
    {
        [, $main] = $this->teacher('Professora Ana');
        $student = $this->student('A-1');
        $this->enroll($this->group($main), $student);
        $manager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($manager, $this->role('dojo', ['dojo.access', 'student.view', 'class.view', 'assessment.view']));

        $this->actingAs($manager)->get(route('operation.students', $this->dojo))->assertOk()->assertSee('Acompanhamento');
        $this->get(route('operation.progression.show', [$this->dojo, $student]))->assertOk()->assertDontSee('>Avaliar<', false);
        $this->get(route('operation.progression.assess', [$this->dojo, $student, $this->art]))->assertForbidden();
        $this->post(route('operation.progression.assess.store', [$this->dojo, $student, $this->art]), ['levels' => [$this->items['O-goshi']->id => 2], 'assessed_on' => '2026-10-15'])->assertForbidden();

        $viewer = $this->associationUser(['student.view', 'assessment.view']);
        $this->actingAs($viewer)->get(route('associations.students.show', [$this->association, $student]))->assertOk()->assertSee('Acompanhamento técnico');
        $this->get(route('associations.students.progression', [$this->association, $student]))->assertOk()->assertSee('O que falta para Faixa amarela');
        $this->actingAs($this->associationUser(['student.view']))->get(route('associations.students.progression', [$this->association, $student]))->assertForbidden();

        $outside = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra', 'status' => 'active']);
        $outsider = User::factory()->create(['association_id' => $outside->id]);
        $role = Role::query()->create(['association_id' => $outside->id, 'name' => 'Gestor', 'code' => 'r_'.Str::random(6), 'scope_type' => 'association']);
        $role->permissions()->sync([Permission::query()->firstOrCreate(['code' => 'assessment.view'], ['description' => 'x'])->id]);
        $outsider->roles()->attach($role, ['association_id' => $outside->id]);
        $this->actingAs($outsider)->get(route('associations.students.progression', [$outside, $student]))->assertNotFound();
    }

    public function test_each_martial_art_has_its_own_follow_up(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $student = $this->student('A-1');
        $this->enroll($this->group($teacher), $student);
        $karate = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Karatê']);
        Rank::query()->create(['martial_art_id' => $karate->id, 'name' => 'k1', 'display_name' => 'Faixa vermelha', 'sort_order' => 1, 'status' => 'active']);
        $student->martialArts()->create(['martial_art_id' => $karate->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        $this->actingAs($user)->post(route('operation.progression.assess.store', [$this->dojo, $student, $this->art]), ['levels' => [$this->items['O-goshi']->id => 3], 'assessed_on' => '2026-10-15']);
        $this->get(route('operation.progression.show', [$this->dojo, $student, 'modalidade' => $this->art->id]))->assertOk()->assertSee('Karatê');
        $snapshot = app(StudentProgressService::class)->snapshot($this->association, $student, $karate);
        $this->assertSame('k1', $snapshot['next_rank']->name);
        $this->assertTrue($snapshot['rows']->isEmpty());
        $this->get(route('operation.progression.show', [$this->dojo, $student, 'modalidade' => $karate->id]))->assertForbidden();
    }
}
