<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\RankCurriculumEntry;
use App\Models\Role;
use App\Models\Student;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\TechniqueAssessment;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\Progression\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechniqueAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private Dojo $dojo;

    private MartialArt $art;

    private Rank $white;

    private Rank $yellow;

    /** @var array<string, TechniqueItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 08:00');
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $this->art = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Judô']);
        $this->white = Rank::query()->create(['martial_art_id' => $this->art->id, 'name' => 'branca', 'display_name' => 'Faixa branca', 'sort_order' => 1, 'status' => 'active']);
        $this->yellow = Rank::query()->create(['martial_art_id' => $this->art->id, 'name' => 'amarela', 'display_name' => 'Faixa amarela', 'sort_order' => 2, 'minimum_age' => 6, 'minimum_months' => 6, 'status' => 'active']);
        Rank::query()->create(['martial_art_id' => $this->art->id, 'name' => 'laranja', 'display_name' => 'Faixa laranja', 'sort_order' => 3, 'status' => 'active']);

        $theory = $this->item('Teoria', 'theoretical');
        $history = $this->item('História', 'theoretical', $theory, 'group');
        $rules = $this->item('Regras', 'theoretical', $theory, 'group');
        $this->items['kano'] = $this->item('Jigoro Kano', 'theoretical', $history);
        $this->items['shido'] = $this->item('Shido', 'theoretical', $rules);
        foreach (['Nage-waza' => 'O-goshi', 'Katame-waza' => 'Kesa-gatame', 'Kata' => 'Nage-no-kata'] as $root => $name) {
            $this->items[$name] = $this->item($name, 'practical', $this->item($root, 'practical'));
        }
        foreach (['kano' => 2, 'shido' => 2, 'O-goshi' => 3, 'Kesa-gatame' => 2, 'Nage-no-kata' => 2] as $key => $level) {
            RankCurriculumEntry::query()->create(['association_id' => $this->association->id, 'rank_id' => $this->yellow->id, 'technique_item_id' => $this->items[$key]->id, 'required_level' => $level]);
        }
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

    private function item(string $name, string $knowledge, ?TechniqueItem $parent = null, string $kind = 'item'): TechniqueItem
    {
        return TechniqueItem::query()->create([
            'association_id' => $this->association->id, 'martial_art_id' => $this->art->id, 'parent_id' => $parent?->id,
            'kind' => $parent === null ? 'group' : $kind, 'knowledge_type' => $knowledge, 'name' => $name, 'status' => 'active',
        ]);
    }

    /** @return array{User, TeacherProfile} */
    private function teacher(string $name): array
    {
        $person = $this->person($name);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $this->association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id]);
        $this->grant($user, $this->role('teacher', ['dojo.access', 'class.view', 'session.view', 'assessment.view', 'assessment.manage']));
        DojoTeacherAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'teacher_profile_id' => $teacher->id, 'start_date' => '2026-09-01', 'created_by_user_id' => $user->id]);

        return [$user, $teacher];
    }

    private function role(string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => $scope, 'code' => $scope.'_'.Str::random(8), 'scope_type' => $scope]);
        $role->permissions()->sync(collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));

        return $role;
    }

    private function grant(User $user, Role $role): void
    {
        DojoRoleAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'user_id' => $user->id, 'role_id' => $role->id, 'start_date' => '2026-09-01', 'created_by_user_id' => $user->id]);
    }

    private function associationUser(array $codes): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $user->roles()->attach($this->role('association', $codes), ['association_id' => $this->association->id]);

        return $user;
    }

    private function person(string $name, ?string $birthDate = '2000-01-01'): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => $birthDate]);
        $this->association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);

        return $person;
    }

    private function student(string $registration, ?string $birthDate = '2000-01-01', string $rankSince = '2026-01-01'): Student
    {
        $student = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person($registration, $birthDate)->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $this->dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $this->dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $link = $student->martialArts()->create(['martial_art_id' => $this->art->id, 'start_date' => '2026-01-01', 'status' => 'active', 'current_rank_id' => $this->white->id]);
        $link->rankHistory()->create(['rank_id' => $this->white->id, 'achieved_at' => $rankSince]);

        return $student;
    }

    private function group(TeacherProfile $teacher): ClassGroup
    {
        return ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma A', 'main_teacher_id' => $teacher->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }

    private function enroll(ClassGroup $group, Student $student): ClassEnrollment
    {
        return ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }
}
