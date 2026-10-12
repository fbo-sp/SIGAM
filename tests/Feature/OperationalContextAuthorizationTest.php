<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDojoHistory;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Access\AccessAdministration;
use App\Services\Access\OperationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalContextAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dojo_grant_limits_lists_and_does_not_unlock_legacy_routes(): void
    {
        [$association, $dojoA, $dojoB] = $this->organization();
        $user = User::factory()->create(['association_id' => $association->id, 'dojo_id' => $dojoB->id]);
        $role = $this->role($association, 'dojo', ['dojo.access', 'student.view']);
        $this->grant($user, $role, $dojoA);
        $studentA = $this->student($association, $dojoA, 'A-1');
        $studentB = $this->student($association, $dojoB, 'B-1');

        $this->actingAs($user)->get(route('operation.index'))->assertOk()->assertSee($dojoA->name)->assertDontSee($dojoB->name);
        $this->get(route('operation.students', $dojoA))->assertOk()->assertSee($studentA->registration_number)->assertDontSee($studentB->registration_number);
        $this->get(route('operation.home', $dojoB))->assertForbidden();
        $this->get(route('associations.students.index', $association))->assertForbidden();
    }

    public function test_expired_future_and_revoked_grants_have_no_effect(): void
    {
        [$association, $dojo] = $this->organization(false);
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'dojo', ['dojo.access']);

        $future = $this->grant($user, $role, $dojo, today()->addDay()->toDateString());
        $this->assertFalse(app(OperationalScope::class)->canEnter($user, $dojo));
        $future->update(['start_date' => today()->subDays(2), 'end_date' => today()->subDay()]);
        $this->assertFalse(app(OperationalScope::class)->canEnter($user, $dojo));
        $future->update(['end_date' => null, 'revoked_at' => now()]);
        $this->assertFalse(app(OperationalScope::class)->canEnter($user, $dojo));
    }

    public function test_revocation_has_immediate_effect_without_changing_other_dojo_grants(): void
    {
        [$association, $dojoA, $dojoB] = $this->organization();
        $admin = $this->associationUser($association, ['role.manage']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'dojo', ['dojo.access']);
        $grantA = $this->grant($user, $role, $dojoA);
        $this->grant($user, $role, $dojoB);
        $scope = app(OperationalScope::class);
        $this->assertTrue($scope->canEnter($user, $dojoA));
        $this->assertTrue($scope->canEnter($user, $dojoB));

        $this->actingAs($admin);
        app(AccessAdministration::class)->revoke($admin, $association->fresh(), $grantA);
        $this->assertFalse($scope->canEnter($user, $dojoA));
        $this->assertTrue($scope->canEnter($user, $dojoB));
    }

    public function test_association_manager_cannot_grant_access_to_external_resources(): void
    {
        [$associationA] = $this->organization(false);
        $associationB = Association::query()->create(['legal_name' => 'Externa', 'trade_name' => 'Externa']);
        $dojoB = Dojo::query()->create(['association_id' => $associationB->id, 'code' => 'EXT', 'name' => 'Dojo externo']);
        $external = User::factory()->create(['association_id' => $associationB->id]);
        $admin = $this->associationUser($associationA, ['role.manage']);
        $role = $this->role($associationA, 'dojo', ['dojo.access']);

        $this->actingAs($admin)->post(route('dojo-access.store', $associationA), [
            'user_id' => $external->id, 'dojo_id' => $dojoB->id, 'role_id' => $role->id, 'start_date' => today()->toDateString(),
        ])->assertNotFound();
        $this->assertDatabaseCount('dojo_role_assignments', 0);
    }

    public function test_teacher_sees_and_records_only_a_designated_session(): void
    {
        [$association, $dojo] = $this->organization(false);
        $teacherPerson = $this->person($association, 'Professora Ana');
        $teacher = TeacherProfile::query()->create(['person_id' => $teacherPerson->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $association->id, 'person_id' => $teacherPerson->id]);
        $role = $this->role($association, 'teacher', ['dojo.access', 'class.view', 'session.view', 'attendance.manage']);
        $this->grant($user, $role, $dojo);
        DojoTeacherAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'teacher_profile_id' => $teacher->id, 'start_date' => today()->subDay(), 'created_by_user_id' => $user->id]);
        $ownGroup = $this->group($association, $dojo, 'Turma da Ana', $teacher);
        $otherGroup = $this->group($association, $dojo, 'Turma alheia');
        $ownSession = $this->classSession($ownGroup);
        $otherSession = $this->classSession($otherGroup, '19:00');
        $student = $this->student($association, $dojo, 'A-2');
        ClassEnrollment::query()->create(['class_group_id' => $ownGroup->id, 'student_id' => $student->id, 'start_date' => today()->subDay(), 'status' => 'active']);

        $this->actingAs($user)->get(route('operation.sessions', $dojo))->assertOk()->assertSee('Turma da Ana')->assertDontSee('Turma alheia');
        $this->get(route('operation.home', $dojo))->assertOk()->assertSee('Aulas e chamada')->assertDontSee('Alunos do dojo');
        $this->get(route('operation.classes', $dojo))->assertOk()->assertSee('Turma da Ana')->assertDontSee('Turma alheia');
        $this->get(route('operation.session', [$dojo, $otherSession]))->assertNotFound();
        $this->post(route('operation.attendance', [$dojo, $ownSession]), ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertRedirect();
        $this->assertDatabaseHas('attendance', ['class_session_id' => $ownSession->id, 'student_id' => $student->id]);
        $this->post(route('operation.attendance', [$dojo, $otherSession]), ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertNotFound();
        $this->get(route('associations.attendance.index', $association))->assertForbidden();
    }

    public function test_teacher_grant_requires_active_institutional_teacher(): void
    {
        [$association, $dojo] = $this->organization(false);
        $admin = $this->associationUser($association, ['role.manage']);
        $ordinary = User::factory()->create(['association_id' => $association->id]);
        $teacherRole = $this->role($association, 'teacher', ['dojo.access']);

        $this->actingAs($admin)->get(route('dojo-access.index', $association))->assertOk()->assertSee('Acessos aos dojos');
        $this->actingAs($admin)->post(route('dojo-access.store', $association), [
            'user_id' => $ordinary->id, 'dojo_id' => $dojo->id, 'role_id' => $teacherRole->id, 'start_date' => today()->toDateString(),
        ])->assertSessionHasErrors('user_id');
        $this->assertDatabaseCount('dojo_role_assignments', 0);
    }

    public function test_overlapping_period_is_rejected_but_exact_replay_is_idempotent(): void
    {
        [$association, $dojo] = $this->organization(false);
        $admin = $this->associationUser($association, ['role.manage']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'dojo', ['dojo.access']);
        $service = app(AccessAdministration::class);
        $data = ['user_id' => $user->id, 'dojo_id' => $dojo->id, 'role_id' => $role->id, 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(5)->toDateString()];

        $this->actingAs($admin);
        $this->assertTrue($admin->hasPermission('role.manage', $association->id));
        $first = $service->grant($admin, $association, $data);
        $this->assertTrue($admin->hasPermission('role.manage', $association->id));
        $this->assertSame($first->id, $service->grant($admin, $association, $data)->id);
        $this->assertTrue($admin->hasPermission('role.manage', $association->id));
        $this->expectException(ValidationException::class);
        $service->grant($admin, $association, [...$data, 'start_date' => today()->addDay()->toDateString()]);
    }

    public function test_regular_student_scope_does_not_use_primary_dojo_as_authorization(): void
    {
        [$association, $dojoA, $dojoB] = $this->organization();
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'dojo', ['dojo.access', 'student.view']);
        $this->grant($user, $role, $dojoB);
        $student = $this->student($association, $dojoA, 'MULTI');
        $student->update(['primary_dojo_id' => $dojoB->id]);

        $this->actingAs($user)->get(route('operation.students', $dojoB))->assertOk()->assertDontSee('MULTI');
        StudentDojoHistory::query()->create(['student_id' => $student->id, 'dojo_id' => $dojoB->id, 'start_date' => today(), 'is_primary' => false]);
        $this->get(route('operation.students', $dojoB))->assertOk()->assertSee('MULTI');
    }

    public function test_teacher_backfill_is_dry_run_by_default_and_never_grants_dojo_access(): void
    {
        [$association] = $this->organization(false);
        $person = $this->person($association, 'Professor legado');
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);

        $this->artisan('sigam:access:backfill-teachers')->assertSuccessful();
        $this->assertDatabaseCount('teacher_association_memberships', 0);
        $this->artisan('sigam:access:backfill-teachers --apply')->assertSuccessful();
        $this->assertDatabaseHas('teacher_association_memberships', ['association_id' => $association->id, 'teacher_profile_id' => $teacher->id, 'source' => 'legacy_backfill']);
        $this->assertDatabaseCount('dojo_role_assignments', 0);
        $this->assertDatabaseCount('dojo_teacher_assignments', 0);
    }

    private function organization(bool $second = true): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $dojoA = Dojo::query()->create(['association_id' => $association->id, 'code' => 'A', 'name' => 'Dojo Norte']);
        $result = [$association, $dojoA];
        if ($second) {
            $result[] = Dojo::query()->create(['association_id' => $association->id, 'code' => 'B', 'name' => 'Dojo Sul']);
        }

        return $result;
    }

    private function role(Association $association, string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $association->id, 'name' => $scope, 'code' => $scope.'_'.uniqid(), 'scope_type' => $scope]);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id);
        $role->permissions()->sync($permissions);

        return $role;
    }

    private function grant(User $user, Role $role, Dojo $dojo, ?string $start = null): DojoRoleAssignment
    {
        return DojoRoleAssignment::query()->create(['association_id' => $dojo->association_id, 'dojo_id' => $dojo->id, 'user_id' => $user->id, 'role_id' => $role->id, 'start_date' => $start ?? today(), 'created_by_user_id' => $user->id]);
    }

    private function associationUser(Association $association, array $codes): User
    {
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'association', $codes);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }

    private function person(Association $association, string $name): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => today()->subYear(), 'status' => 'active']);

        return $person;
    }

    private function student(Association $association, Dojo $dojo, string $registration): Student
    {
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $this->person($association, $registration)->id, 'registration_number' => $registration, 'entry_date' => today()->subYear(), 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        StudentDojoHistory::query()->create(['student_id' => $student->id, 'dojo_id' => $dojo->id, 'start_date' => today()->subYear(), 'is_primary' => true]);

        return $student;
    }

    private function group(Association $association, Dojo $dojo, string $name, ?TeacherProfile $teacher = null): ClassGroup
    {
        $art = MartialArt::query()->firstOrCreate(['association_id' => $association->id, 'name' => 'Judô'], ['status' => 'active']);

        return ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => $name, 'main_teacher_id' => $teacher?->id, 'start_date' => today()->subMonth(), 'status' => 'active']);
    }

    private function classSession(ClassGroup $group, string $start = '18:00'): ClassSession
    {
        return ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today(), 'start_time' => $start, 'end_time' => $start === '18:00' ? '19:00' : '20:00', 'status' => 'planned', 'attendance_state' => 'open']);
    }
}
