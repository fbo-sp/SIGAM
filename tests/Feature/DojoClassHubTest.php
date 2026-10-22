<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\AttendanceRecord;
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
use App\Models\Role;
use App\Models\Student;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Classes\ClassGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DojoClassHubTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private Dojo $dojo;

    private MartialArt $art;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 08:00');
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $this->art = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Judô']);
    }

    public function test_dojo_manager_creates_class_in_own_dojo_and_teacher_cannot(): void
    {
        $manager = $this->dojoManager();
        $other = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        [$teacherUser] = $this->teacher('Professora Ana');

        $this->actingAs($manager)->get(route('operation.classes.create', $this->dojo))->assertOk()->assertSee('Dojo Norte');
        $this->post(route('operation.classes.store', $this->dojo), [
            'dojo_id' => $other->id, 'martial_art_id' => $this->art->id, 'name' => 'Judô Adulto', 'start_date' => '2026-10-01',
            'weekdays' => [1, 3], 'start_time' => '19:00', 'end_time' => '20:00',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $group = ClassGroup::query()->firstOrFail();
        $this->assertSame($this->dojo->id, $group->dojo_id, 'A rota do dojo prevalece sobre o dojo_id enviado.');
        $this->assertSame(2, $group->schedules()->count());
        $this->get(route('operation.classes.show', [$this->dojo, $group]))->assertOk()->assertSee('Judô Adulto')->assertSee('Matricular aluno');

        $this->actingAs($teacherUser)->get(route('operation.classes.create', $this->dojo))->assertForbidden();
        $this->get(route('operation.classes.edit', [$this->dojo, $group]))->assertForbidden();
    }

    public function test_changing_main_teacher_updates_future_open_sessions_only(): void
    {
        $manager = $this->dojoManager();
        [, $ana] = $this->teacher('Professora Ana');
        [, $bia] = $this->teacher('Professora Bia');
        $group = $this->group('Turma A', $ana);
        $group->schedules()->create(['weekday' => 4, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-09-01', 'status' => 'active']);
        $past = $this->classSession($group, '2026-10-08', $ana);
        $future = $this->classSession($group, '2026-10-22', $ana);

        $this->actingAs($manager)->put(route('operation.classes.update', [$this->dojo, $group]), $this->updatePayload($group, ['main_teacher_id' => $bia->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($bia->id, $group->fresh()->main_teacher_id);
        $this->assertSame($bia->id, $future->fresh()->planned_teacher_id);
        $this->assertSame($ana->id, $past->fresh()->planned_teacher_id);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'class_group.updated']);
    }

    public function test_end_date_cancels_later_sessions_and_capacity_cannot_drop_below_enrollments(): void
    {
        $manager = $this->dojoManager();
        $group = $this->group('Turma A');
        $before = $this->classSession($group, '2026-10-20');
        $after = $this->classSession($group, '2026-10-29');
        $this->enroll($group, $this->student('A-1'));
        $this->enroll($group, $this->student('A-2'));

        $this->actingAs($manager)->put(route('operation.classes.update', [$this->dojo, $group]), $this->updatePayload($group, ['capacity' => 1]))
            ->assertSessionHasErrors('capacity');
        $this->put(route('operation.classes.update', [$this->dojo, $group]), $this->updatePayload($group, ['end_date' => '2026-10-25']))
            ->assertSessionHasNoErrors();

        $this->assertSame('planned', $before->fresh()->status);
        $this->assertSame('cancelled', $after->fresh()->status);
    }

    public function test_inactivating_is_blocked_when_a_later_session_has_attendance(): void
    {
        $manager = $this->dojoManager();
        $group = $this->group('Turma A');
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        $session = $this->classSession($group, '2026-10-20');
        AttendanceRecord::query()->create(['class_session_id' => $session->id, 'student_id' => $student->id, 'status' => 'present', 'participation_type' => 'regular', 'recorded_at' => now(), 'recorded_by_user_id' => $manager->id]);

        $this->actingAs($manager)->put(route('operation.classes.update', [$this->dojo, $group]), $this->updatePayload($group, ['status' => 'inactive']))
            ->assertSessionHasErrors('status');
        $this->assertSame('active', $group->fresh()->status);
    }

    public function test_ended_enrollment_stays_in_earlier_rosters_and_leaves_later_ones(): void
    {
        $manager = $this->dojoManager();
        $group = $this->group('Turma A');
        $student = $this->student('A-1');
        $enrollment = $this->enroll($group, $student);
        $earlier = $this->classSession($group, '2026-10-08');
        $later = $this->classSession($group, '2026-10-22');

        $this->actingAs($manager)->post(route('operation.classes.enrollments.end', [$this->dojo, $group, $enrollment]), ['end_date' => '2026-10-10', 'exit_reason' => 'Mudou de horário'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ended', $enrollment->fresh()->status);
        $roster = app(AttendanceService::class);
        $this->assertTrue($roster->roster($earlier)->contains(fn ($row) => $row['student']->id === $student->id));
        $this->assertFalse($roster->roster($later)->contains(fn ($row) => $row['student']->id === $student->id));
        $this->post(route('operation.classes.enrollments.end', [$this->dojo, $group, $enrollment]), ['end_date' => '2026-10-12'])->assertSessionHasErrors('end_date');
    }

    public function test_enrollment_cannot_end_before_recorded_attendance(): void
    {
        $manager = $this->dojoManager();
        $group = $this->group('Turma A');
        $student = $this->student('A-1');
        $enrollment = $this->enroll($group, $student);
        $session = $this->classSession($group, '2026-10-13');
        AttendanceRecord::query()->create(['class_session_id' => $session->id, 'student_id' => $student->id, 'status' => 'present', 'participation_type' => 'regular', 'recorded_at' => now(), 'recorded_by_user_id' => $manager->id]);

        $this->actingAs($manager)->post(route('operation.classes.enrollments.end', [$this->dojo, $group, $enrollment]), ['end_date' => '2026-10-10'])
            ->assertSessionHasErrors('end_date');
        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_assistant_teacher_needs_dojo_link_and_then_runs_attendance(): void
    {
        $manager = $this->dojoManager();
        [, $ana] = $this->teacher('Professora Ana');
        [$biaUser, $bia] = $this->teacher('Professora Bia', linked: false);
        $group = $this->group('Turma A', $ana);
        $session = $this->classSession($group, '2026-10-15', $ana);
        $student = $this->student('A-1');
        $this->enroll($group, $student);

        $this->actingAs($manager)->post(route('operation.classes.teachers.store', [$this->dojo, $group]), ['teacher_profile_id' => $bia->id, 'start_date' => '2026-10-15'])
            ->assertSessionHasErrors('teacher_profile_id');
        $this->post(route('operation.classes.teachers.store', [$this->dojo, $group]), ['teacher_profile_id' => $ana->id, 'start_date' => '2026-10-15'])
            ->assertSessionHasErrors('teacher_profile_id');

        $this->linkTeacher($bia);
        $this->actingAs($biaUser)->get(route('operation.session', [$this->dojo, $session]))->assertNotFound();
        $this->actingAs($manager)->post(route('operation.classes.teachers.store', [$this->dojo, $group]), ['teacher_profile_id' => $bia->id, 'start_date' => '2026-10-15'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('class_group_teachers', ['class_group_id' => $group->id, 'teacher_profile_id' => $bia->id, 'role_type' => 'assistant']);

        $this->actingAs($biaUser)->get(route('operation.classes', $this->dojo))->assertOk()->assertSee('Turma A');
        $this->get(route('operation.classes.show', [$this->dojo, $group]))->assertOk()->assertDontSee('Matricular aluno');
        $this->post(route('operation.attendance', [$this->dojo, $session]), ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertRedirect();
        $this->assertDatabaseHas('attendance', ['class_session_id' => $session->id, 'student_id' => $student->id]);
        $this->post(route('operation.classes.enrollments.store', [$this->dojo, $group]), ['student_id' => $student->id, 'start_date' => '2026-10-15'])->assertForbidden();

        $assignment = ClassGroupTeacher::query()->firstOrFail();
        $this->actingAs($manager)->post(route('operation.classes.teachers.end', [$this->dojo, $group, $assignment]), ['end_date' => '2026-10-15'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-15', $assignment->fresh()->end_date->toDateString());
    }

    public function test_attendance_opens_on_the_session_day_and_teacher_with_permission_reopens(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $group = $this->group('Turma A', $ana);
        $session = $this->classSession($group, today()->addDay()->toDateString(), $ana);
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        $records = ['records' => [['student_id' => $student->id, 'status' => 'present']]];

        $this->actingAs($anaUser)->get(route('operation.session', [$this->dojo, $session]))->assertOk()
            ->assertSee('Esta aula ainda não aconteceu')->assertDontSee('Finalizar chamada');
        $this->post(route('operation.attendance', [$this->dojo, $session]), $records)->assertSessionHasErrors('attendance');
        $this->post(route('operation.attendance', [$this->dojo, $session]), [...$records, 'close_attendance' => '1'])->assertSessionHasErrors('attendance');
        $this->actingAs($this->dojoManager())->post(route('operation.attendance', [$this->dojo, $session]), [...$records, 'close_attendance' => '1'])->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance', 0);
        $this->assertSame('open', $session->fresh()->attendance_state);

        $this->travelTo($session->session_date->setTime(7, 0));
        $this->actingAs($anaUser)->get(route('operation.session', [$this->dojo, $session]))->assertOk()->assertSee('Finalizar chamada');
        $this->post(route('operation.attendance', [$this->dojo, $session]), [...$records, 'close_attendance' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $session->fresh()->attendance_state);
        $this->get(route('operation.session', [$this->dojo, $session]))->assertDontSee('Reabrir chamada');
        $this->post(route('operation.attendance.reopen', [$this->dojo, $session]), ['reason' => 'Corrigir a presença do aluno.'])->assertNotFound();

        $this->grant($anaUser, $this->role('teacher', ['dojo.access', 'session.view', 'attendance.reopen']));
        $this->get(route('operation.session', [$this->dojo, $session]))->assertOk()->assertSee('Reabrir chamada');
        $this->post(route('operation.attendance.reopen', [$this->dojo, $session]), ['reason' => 'curto'])->assertSessionHasErrors('reason');
        $this->post(route('operation.attendance.reopen', [$this->dojo, $session]), ['reason' => 'Corrigir a presença do aluno.'])->assertSessionHasNoErrors();
        $this->assertSame('open', $session->fresh()->attendance_state);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'attendance.reopened']);
        $this->post(route('operation.attendance', [$this->dojo, $session]), ['records' => [['student_id' => $student->id, 'status' => 'absent']], 'close_attendance' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance', ['class_session_id' => $session->id, 'student_id' => $student->id, 'status' => 'absent']);
    }

    public function test_promoting_assistant_to_main_ends_assistant_assignment_and_keeps_attendance_access(): void
    {
        $manager = $this->dojoManager();
        [, $ana] = $this->teacher('Professora Ana');
        [$biaUser, $bia] = $this->teacher('Professora Bia');
        $group = $this->group('Turma A', $ana);
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        $pending = $this->classSession($group, '2026-10-13', $ana);
        $future = $this->classSession($group, '2026-10-22', $ana);
        $assignment = ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $bia->id, 'role_type' => 'assistant', 'start_date' => '2026-10-01']);

        $this->actingAs($manager)->post(route('operation.classes.teachers.store', [$this->dojo, $group]), ['teacher_profile_id' => $ana->id, 'start_date' => '2026-10-15'])
            ->assertSessionHasErrors('teacher_profile_id');
        $this->put(route('operation.classes.update', [$this->dojo, $group]), $this->updatePayload($group, ['main_teacher_id' => $bia->id]))
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($message) => str_contains($message, 'designação de auxiliar foi encerrada'));

        $this->assertSame('2026-10-14', $assignment->fresh()->end_date->toDateString());
        $this->assertSame($bia->id, $future->fresh()->planned_teacher_id);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'class_group_teacher.ended']);
        $this->actingAs($biaUser)->get(route('operation.session', [$this->dojo, $future]))->assertOk();
        $this->post(route('operation.attendance', [$this->dojo, $pending]), ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertRedirect();
        $this->assertDatabaseHas('attendance', ['class_session_id' => $pending->id, 'student_id' => $student->id]);
    }

    public function test_class_from_another_dojo_is_not_found(): void
    {
        $manager = $this->dojoManager();
        $other = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $foreign = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $other->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma Sul', 'start_date' => '2026-09-01', 'status' => 'active']);

        $this->actingAs($manager)->get(route('operation.classes.show', [$this->dojo, $foreign]))->assertNotFound();
        $this->put(route('operation.classes.update', [$this->dojo, $foreign]), $this->updatePayload($foreign))->assertNotFound();
    }

    public function test_dojo_lists_show_class_and_empty_roster_warning(): void
    {
        $manager = $this->dojoManager();
        $group = $this->group('Turma Kids');
        $enrolled = $this->student('A-1');
        $this->enroll($group, $enrolled);
        $this->student('A-2');
        $empty = $this->group('Turma Vazia');
        $session = $this->classSession($empty, '2026-10-15');

        $this->actingAs($manager)->get(route('operation.students', $this->dojo))->assertOk()->assertSee('Turma Kids')->assertSee('Sem turma');
        $this->get(route('operation.session', [$this->dojo, $session]))->assertOk()
            ->assertSee('Nenhum aluno matriculado nesta turma')->assertSee(route('operation.classes.show', [$this->dojo, $empty]));
    }

    public function test_enrollment_without_birth_date_works_when_class_has_no_age_range(): void
    {
        $group = $this->group('Turma Livre');
        $student = $this->student('A-1', null);

        $enrollment = app(ClassGroupService::class)->enroll($this->association, $group, ['student_id' => $student->id, 'start_date' => '2026-10-15']);
        $this->assertSame('active', $enrollment->status);

        $group->update(['minimum_age' => 7]);
        $this->expectException(ValidationException::class);
        app(ClassGroupService::class)->enroll($this->association, $group, ['student_id' => $this->student('A-2', null)->id, 'start_date' => '2026-10-15']);
    }

    public function test_direct_registration_enrolls_in_selected_class_only_when_activating(): void
    {
        $manager = $this->dojoManager();
        $group = $this->group('Turma Kids');
        $payload = fn (string $action) => [
            'idempotency_key' => (string) Str::uuid(), 'person_mode' => 'new',
            'person' => ['full_name' => 'Aluno '.Str::random(6), 'birth_date' => '2000-01-01'],
            'dojo_id' => $this->dojo->id, 'entry_date' => '2026-10-15', 'martial_art_id' => $this->art->id,
            'guardian_mode' => 'none', 'finish_action' => $action, 'class_group_ids' => [$group->id],
        ];

        $this->actingAs($manager)->get(route('operation.students.direct.create', $this->dojo))->assertOk()->assertSee('Turma Kids');
        $this->post(route('operation.students.direct.store', $this->dojo), $payload('activate'))->assertSessionHasNoErrors();
        $this->post(route('operation.students.direct.store', $this->dojo), $payload('pre_registration'))
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'não foram gravadas'));

        $this->assertSame(1, ClassEnrollment::query()->where('class_group_id', $group->id)->where('status', 'active')->count());
    }

    public function test_association_manager_enrolls_and_ends_from_student_page(): void
    {
        $manager = User::factory()->create(['association_id' => $this->association->id]);
        $role = $this->role('association', ['student.view', 'enrollment.manage']);
        $manager->roles()->attach($role, ['association_id' => $this->association->id]);
        $group = $this->group('Turma Kids');
        $student = $this->student('A-1');

        $this->actingAs($manager)->get(route('associations.students.show', [$this->association, $student]))->assertOk()->assertSee('não está em nenhuma turma');
        $this->post(route('associations.students.enrollments.store', [$this->association, $student]), ['class_group_id' => $group->id, 'start_date' => '2026-10-15'])
            ->assertSessionHasNoErrors();
        $enrollment = ClassEnrollment::query()->firstOrFail();
        $this->get(route('associations.students.show', [$this->association, $student]))->assertOk()->assertSee('Turma Kids');
        $this->post(route('associations.students.enrollments.end', [$this->association, $student, $enrollment]), ['end_date' => '2026-10-15'])->assertSessionHasNoErrors();
        $this->assertSame('ended', $enrollment->fresh()->status);
    }

    private function dojoManager(): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($user, $this->role('dojo', ['dojo.access', 'student.view', 'student.manage', 'class.view', 'class.manage', 'enrollment.manage', 'session.view', 'attendance.manage']));

        return $user;
    }

    /** @return array{User, TeacherProfile} */
    private function teacher(string $name, bool $linked = true): array
    {
        $person = $this->person($name);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        $teacher->martialArts()->create(['martial_art_id' => $this->art->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $this->association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id]);
        $this->grant($user, $this->role('teacher', ['dojo.access', 'class.view', 'session.view', 'attendance.manage']));
        if ($linked) {
            $this->linkTeacher($teacher);
        }

        return [$user, $teacher];
    }

    private function linkTeacher(TeacherProfile $teacher): void
    {
        DojoTeacherAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'teacher_profile_id' => $teacher->id, 'start_date' => '2026-09-01', 'created_by_user_id' => User::query()->value('id') ?? User::factory()->create()->id]);
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

    private function person(string $name, ?string $birthDate = '2000-01-01'): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => $birthDate]);
        $this->association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);

        return $person;
    }

    private function student(string $registration, ?string $birthDate = '2000-01-01'): Student
    {
        $student = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person($registration, $birthDate)->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $this->dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $this->dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $student->martialArts()->create(['martial_art_id' => $this->art->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return $student;
    }

    private function group(string $name, ?TeacherProfile $teacher = null): ClassGroup
    {
        return ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => $name, 'main_teacher_id' => $teacher?->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }

    private function enroll(ClassGroup $group, Student $student): ClassEnrollment
    {
        return ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }

    private function classSession(ClassGroup $group, string $date, ?TeacherProfile $teacher = null): ClassSession
    {
        return ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'planned_teacher_id' => $teacher?->id, 'status' => 'planned', 'attendance_state' => 'open']);
    }

    private function updatePayload(ClassGroup $group, array $changes = []): array
    {
        return [...['name' => $group->name, 'main_teacher_id' => $group->main_teacher_id, 'future_session_target' => 8, 'status' => 'active'], ...$changes];
    }
}
