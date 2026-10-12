<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportsRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_configures_martial_art_and_rank(): void
    {
        [$association, $user] = $this->context(['martial_art.view', 'martial_art.manage']);

        $this->actingAs($user)->post(route('associations.martial-arts.store', $association), [
            'name' => 'Judô', 'description' => 'Arte suave', 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $martialArt = MartialArt::query()->firstOrFail();
        $this->post(route('associations.ranks.store', [$association, $martialArt]), [
            'name' => 'white', 'display_name' => 'Faixa Branca', 'sort_order' => 1, 'color_name' => 'Branca', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ranks', ['martial_art_id' => $martialArt->id, 'display_name' => 'Faixa Branca']);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $association->id, 'event_type' => 'rank.saved']);
    }

    public function test_manager_can_choose_template_when_creating_martial_art(): void
    {
        [$association, $user] = $this->context(['martial_art.view', 'martial_art.manage']);

        $this->actingAs($user)->get(route('associations.catalog.index', $association))
            ->assertOk()
            ->assertSee('Começar em branco')
            ->assertSee('Judô — referência CBJ 2023');

        $this->post(route('associations.martial-arts.store', $association), [
            'template_key' => 'judo_cbj_2023',
            'name' => '',
            'description' => '',
            'status' => 'active',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Modalidade cadastrada com 22 graduações do template.');

        $martialArt = MartialArt::query()->where('association_id', $association->id)->sole();
        $this->assertSame('Judô', $martialArt->name);
        $this->assertStringContainsString('CBJ', $martialArt->description);
        $this->assertSame(22, $martialArt->ranks()->count());
        $this->assertDatabaseHas('ranks', ['martial_art_id' => $martialArt->id, 'name' => '11-kyu', 'minimum_age' => 4, 'minimum_months' => 3]);
        $this->assertDatabaseHas('ranks', ['martial_art_id' => $martialArt->id, 'name' => '10-dan', 'sort_order' => 22]);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $association->id, 'event_type' => 'rank.template_applied', 'entity_id' => $martialArt->id]);
    }

    public function test_invalid_template_does_not_create_partial_catalog(): void
    {
        [$association, $user] = $this->context(['martial_art.manage']);

        $this->actingAs($user)->post(route('associations.martial-arts.store', $association), [
            'template_key' => 'template-inexistente',
            'name' => '',
            'status' => 'active',
        ])->assertSessionHasErrors('template_key');

        $this->assertDatabaseCount('martial_arts', 0);
        $this->assertDatabaseCount('ranks', 0);
    }

    public function test_user_without_management_permission_cannot_apply_template(): void
    {
        [$association, $user] = $this->context(['martial_art.view']);

        $this->actingAs($user)->post(route('associations.martial-arts.store', $association), [
            'template_key' => 'judo_cbj_2023',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseCount('martial_arts', 0);
    }

    public function test_student_pre_registration_creates_historical_links(): void
    {
        [$association, $user, $person, $dojo, $martialArt, $rank] = $this->sportsContext(['student.view', 'student.manage'], '2000-01-01');

        $this->actingAs($user)->post(route('associations.students.store', $association), [
            'person_id' => $person->id, 'registration_number' => 'alu-001', 'entry_date' => '2026-08-30', 'primary_dojo_id' => $dojo->id, 'martial_art_id' => $martialArt->id, 'rank_id' => $rank->id,
        ])->assertSessionHasNoErrors();

        $student = Student::query()->firstOrFail();
        $this->assertSame('pre_registration', $student->status);
        $this->assertSame('ALU-001', $student->registration_number);
        $this->assertDatabaseHas('student_dojo_history', ['student_id' => $student->id, 'dojo_id' => $dojo->id, 'is_primary' => true]);
        $this->assertDatabaseHas('student_martial_arts', ['student_id' => $student->id, 'martial_art_id' => $martialArt->id, 'current_rank_id' => $rank->id]);
        $this->assertDatabaseHas('student_rank_history', ['rank_id' => $rank->id, 'source_type' => 'initial_registration']);
    }

    public function test_minor_requires_legal_guardian_before_activation(): void
    {
        [$association, $user, $minor, $dojo, $martialArt] = $this->sportsContext(['student.view', 'student.manage'], '2015-01-01');
        $student = $this->createStudent($association, $minor, $dojo, $martialArt);

        $this->actingAs($user)->post(route('associations.students.activate', [$association, $student]))->assertSessionHasErrors('guardian');
        $this->assertSame('pre_registration', $student->fresh()->status);

        $guardian = Person::query()->create(['full_name' => 'Responsável', 'birth_date' => '1980-01-01']);
        $association->people()->attach($guardian, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $this->post(route('associations.students.guardians.store', [$association, $student]), [
            'guardian_person_id' => $guardian->id, 'relationship_type' => 'mother', 'is_legal_guardian' => '1', 'is_primary' => '1', 'can_receive_notifications' => '1', 'start_date' => '2026-08-30',
        ])->assertSessionHasNoErrors();
        $this->post(route('associations.students.activate', [$association, $student]))->assertSessionHasNoErrors();

        $this->assertSame('active', $student->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'student.activated', 'association_id' => $association->id]);
    }

    public function test_student_cannot_use_dojo_from_another_association(): void
    {
        [$association, $user, $person, , $martialArt] = $this->sportsContext(['student.manage'], '2000-01-01');
        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $otherDojo = Dojo::query()->create(['association_id' => $other->id, 'code' => 'OUT', 'name' => 'Outro']);

        $this->actingAs($user)->post(route('associations.students.store', $association), [
            'person_id' => $person->id, 'registration_number' => 'A2', 'entry_date' => '2026-08-30', 'primary_dojo_id' => $otherDojo->id, 'martial_art_id' => $martialArt->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('students', 0);
    }

    public function test_teacher_profile_reuses_person_and_adds_ability(): void
    {
        [$association, $user, $person, , $martialArt, $rank] = $this->sportsContext(['teacher.view', 'teacher.manage'], '1990-01-01');

        $this->actingAs($user)->post(route('associations.teachers.store', $association), [
            'person_id' => $person->id, 'registration_number' => 'PROF-1', 'martial_art_id' => $martialArt->id, 'rank_id' => $rank->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teacher_profiles', ['person_id' => $person->id, 'registration_number' => 'PROF-1']);
        $this->assertDatabaseHas('teacher_martial_arts', ['martial_art_id' => $martialArt->id, 'rank_id' => $rank->id]);
    }

    private function sportsContext(array $permissions, string $birthDate): array
    {
        [$association, $user] = $this->context($permissions);
        $person = Person::query()->create(['full_name' => 'Pessoa Teste', 'birth_date' => $birthDate]);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D1', 'name' => 'Dojo 1']);
        $martialArt = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $rank = Rank::query()->create(['martial_art_id' => $martialArt->id, 'name' => 'white', 'display_name' => 'Branca', 'sort_order' => 1]);

        return [$association, $user, $person, $dojo, $martialArt, $rank];
    }

    private function createStudent(Association $association, Person $person, Dojo $dojo, MartialArt $martialArt): Student
    {
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'A1', 'entry_date' => '2026-08-30', 'primary_dojo_id' => $dojo->id, 'status' => 'pre_registration']);
        $student->dojoHistory()->create(['dojo_id' => $dojo->id, 'start_date' => '2026-08-30', 'is_primary' => true]);
        $student->martialArts()->create(['martial_art_id' => $martialArt->id, 'start_date' => '2026-08-30', 'status' => 'active']);

        return $student;
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação A', 'trade_name' => 'A']);
        $permissions = collect($codes)->map(fn (string $code) => Permission::query()->create(['code' => $code, 'description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor', 'code' => 'sports_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $user];
    }
}
