<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonEditShortcutTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private Dojo $dojo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
    }

    public function test_lists_show_edit_icon_only_to_who_can_edit_people(): void
    {
        $student = $this->student('A-1', 'Aluna Bia');
        $teacherPerson = $this->person('Professor Caio');
        TeacherProfile::query()->create(['person_id' => $teacherPerson->id, 'status' => 'active']);
        $editUrl = route('associations.people.edit', [$this->association, $student->person]);

        $manager = $this->associationUser(['person.view', 'person.manage', 'student.view', 'teacher.view']);
        $this->actingAs($manager)->get(route('associations.students.index', $this->association))->assertOk()->assertSee($editUrl, false)->assertSee('Editar dados de Aluna Bia');
        $this->get(route('associations.people.index', $this->association))->assertOk()->assertSee($editUrl, false);
        $this->get(route('associations.teachers.index', $this->association))->assertOk()->assertSee('Editar dados de Professor Caio');

        $viewer = $this->associationUser(['person.view', 'student.view', 'teacher.view']);
        $this->actingAs($viewer)->get(route('associations.students.index', $this->association))->assertOk()->assertDontSee($editUrl, false);
        $this->get(route('associations.teachers.index', $this->association))->assertOk()->assertDontSee('Editar dados de');
        $this->get($editUrl)->assertForbidden();
    }

    public function test_person_edit_returns_to_the_list_and_ignores_external_return(): void
    {
        $student = $this->student('A-1', 'Aluna Bia');
        $manager = $this->associationUser(['person.view', 'person.manage']);
        $list = route('associations.students.index', $this->association, false);
        $data = ['full_name' => 'Aluna Beatriz', 'country' => 'BR', 'status' => 'active'];

        $this->actingAs($manager)->get(route('associations.people.edit', [$this->association, $student->person, 'voltar' => $list]))->assertOk()->assertSee('value="'.$list.'"', false);
        $this->put(route('associations.people.update', [$this->association, $student->person]), [...$data, 'voltar' => $list])->assertRedirect($list);
        $this->assertSame('Aluna Beatriz', $student->person->fresh()->full_name);

        foreach (['//externo.example', 'https://externo.example', '/\\externo.example'] as $external) {
            $this->put(route('associations.people.update', [$this->association, $student->person]), [...$data, 'voltar' => $external])
                ->assertRedirect(route('associations.people.show', [$this->association, $student->person]));
        }
    }

    public function test_dojo_manager_edits_personal_data_of_own_students_but_not_cpf(): void
    {
        $student = $this->student('A-1', 'Aluna Bia', '52998224725');
        $manager = $this->dojoUser(['dojo.access', 'student.view', 'student.manage']);
        $editUrl = route('operation.students.person.edit', [$this->dojo, $student]);

        $this->actingAs($manager)->get(route('operation.students', $this->dojo))->assertOk()->assertSee($editUrl, false);
        $this->get($editUrl)->assertOk()->assertSee('Dados do aluno')->assertDontSee('name="cpf"', false);
        $this->put(route('operation.students.person.update', [$this->dojo, $student]), [
            'full_name' => 'Aluna Beatriz', 'mobile' => '(11) 98888-7777', 'country' => 'BR', 'cpf' => '', 'status' => 'inactive',
        ])->assertSessionHasNoErrors()->assertRedirect(route('operation.students', $this->dojo));

        $person = $student->person->fresh();
        $this->assertSame('Aluna Beatriz', $person->full_name);
        $this->assertSame('11988887777', $person->mobile);
        $this->assertSame('52998224725', $person->cpf);
        $this->assertSame('active', $person->status);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'person.updated', 'association_id' => $this->association->id]);
    }

    public function test_dojo_edit_is_limited_to_current_students_without_teacher_profile(): void
    {
        $other = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $foreign = $this->student('B-1', 'Aluno de fora', null, $other);
        $teaching = $this->student('A-2', 'Aluna professora');
        TeacherProfile::query()->create(['person_id' => $teaching->person_id, 'status' => 'active']);
        $manager = $this->dojoUser(['dojo.access', 'student.view', 'student.manage']);
        $reader = $this->dojoUser(['dojo.access', 'student.view']);

        $this->actingAs($manager)->get(route('operation.students.person.edit', [$this->dojo, $foreign]))->assertNotFound();
        $this->get(route('operation.students.person.edit', [$this->dojo, $teaching]))->assertForbidden();
        $this->get(route('operation.students', $this->dojo))->assertOk()->assertDontSee(route('operation.students.person.edit', [$this->dojo, $teaching]), false);
        $this->actingAs($reader)->get(route('operation.students', $this->dojo))->assertOk()->assertDontSee('Editar dados de');
        $this->put(route('operation.students.person.update', [$this->dojo, $teaching]), ['full_name' => 'X', 'country' => 'BR'])->assertForbidden();
    }

    private function associationUser(array $codes): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $user->roles()->attach($this->role('association', $codes), ['association_id' => $this->association->id]);

        return $user;
    }

    private function dojoUser(array $codes): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        DojoRoleAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'user_id' => $user->id, 'role_id' => $this->role('dojo', $codes)->id, 'start_date' => today()->subDay(), 'created_by_user_id' => $user->id]);

        return $user;
    }

    private function role(string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => $scope, 'code' => $scope.'_'.Str::random(8), 'scope_type' => $scope]);
        $role->permissions()->sync(collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));

        return $role;
    }

    private function person(string $name, ?string $cpf = null): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => '2000-01-01', 'cpf' => $cpf]);
        $this->association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => today()->subYear(), 'status' => 'active']);

        return $person;
    }

    private function student(string $registration, string $name, ?string $cpf = null, ?Dojo $dojo = null): Student
    {
        $dojo ??= $this->dojo;
        $student = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person($name, $cpf)->id, 'registration_number' => $registration, 'entry_date' => today()->subYear(), 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $dojo->id, 'start_date' => today()->subYear(), 'is_primary' => true]);

        return $student;
    }
}
