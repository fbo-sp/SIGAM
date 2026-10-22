<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\AttendanceRecord;
use App\Models\Charge;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
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
use App\Services\Attendance\SessionGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Sprint18Test extends TestCase
{
    use RefreshDatabase;

    public function test_class_is_created_with_multiple_weekdays_and_different_times(): void
    {
        [$association, $user, $host, $art] = $this->context(['class.manage']);

        $this->actingAs($user)->post(route('associations.classes.store', $association), [
            'dojo_id' => $host->id, 'martial_art_id' => $art->id, 'name' => 'Turma semanal',
            'start_date' => '2026-11-01',
            'schedules' => [
                ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00'],
                ['weekday' => 3, 'start_time' => '18:00', 'end_time' => '19:00'],
                ['weekday' => 5, 'start_time' => '17:00', 'end_time' => '18:00'],
            ],
        ])->assertSessionHasNoErrors();

        $group = ClassGroup::query()->firstOrFail();
        $this->assertCount(3, $group->schedules);
        $this->assertSame(0, app(SessionGenerator::class)->generate(Carbon::parse('2026-11-02'), Carbon::parse('2026-11-06'), $association->id));
        $this->assertSame(['2026-11-02 18:00', '2026-11-04 18:00', '2026-11-06 17:00'], $group->sessions()->whereBetween('session_date', ['2026-11-02', '2026-11-06'])->orderBy('session_date')->get()->map(fn ($session) => $session->session_date->format('Y-m-d').' '.substr($session->start_time, 0, 5))->all());
    }

    public function test_schedule_rejects_duplicate_day_and_invalid_time(): void
    {
        [$association, $user, $host, $art] = $this->context(['class.manage']);
        $base = ['dojo_id' => $host->id, 'martial_art_id' => $art->id, 'name' => 'Turma inválida', 'start_date' => '2026-11-01'];

        $this->actingAs($user)->post(route('associations.classes.store', $association), $base + ['schedules' => [
            ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00'],
            ['weekday' => 1, 'start_time' => '20:00', 'end_time' => '21:00'],
        ]])->assertSessionHasErrors('schedules.1.weekday');
        $this->post(route('associations.classes.store', $association), $base + ['schedules' => [
            ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '18:00'],
        ]])->assertSessionHasErrors('schedules.0.end_time');
        $this->assertDatabaseCount('class_groups', 0);
    }

    public function test_schedule_rejects_teacher_time_conflict(): void
    {
        [$association, $user, $host, $art] = $this->context(['class.manage']);
        $teacherPerson = Person::query()->create(['full_name' => 'Professora', 'birth_date' => '1990-01-01']);
        $association->people()->attach($teacherPerson, ['role_type' => 'teacher', 'start_date' => '2026-01-01', 'status' => 'active']);
        $teacher = TeacherProfile::query()->create(['person_id' => $teacherPerson->id, 'status' => 'active']);
        $teacher->martialArts()->create(['martial_art_id' => $art->id, 'status' => 'active']);
        $first = $this->group($association, $host, $art);
        $first->update(['main_teacher_id' => $teacher->id]);
        $first->schedules()->create(['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'teacher_id' => $teacher->id, 'valid_from' => '2026-11-01', 'status' => 'active']);

        $this->actingAs($user)->post(route('associations.classes.store', $association), [
            'dojo_id' => $host->id, 'martial_art_id' => $art->id, 'name' => 'Turma conflitante',
            'main_teacher_id' => $teacher->id, 'start_date' => '2026-11-01',
            'schedules' => [['weekday' => 1, 'start_time' => '18:30', 'end_time' => '19:30']],
        ])->assertSessionHasErrors('schedules.0.start_time');

        $this->assertDatabaseMissing('class_groups', ['name' => 'Turma conflitante']);
    }

    public function test_future_schedule_change_reconciles_planned_sessions_and_preserves_attendance(): void
    {
        // As datas abaixo pressupõem que 2026-10-19 ainda está no horizonte futuro de geração.
        $this->travelTo(Carbon::parse('2026-10-15 08:00'));
        [$association, $user, $host, $art] = $this->context(['class.manage']);
        $group = $this->group($association, $host, $art);
        $monday = ClassSchedule::query()->create(['class_group_id' => $group->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-10-01', 'status' => 'active']);
        $wednesday = ClassSchedule::query()->create(['class_group_id' => $group->id, 'weekday' => 3, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-10-01', 'status' => 'active']);
        $planned = ClassSession::query()->create(['class_group_id' => $group->id, 'class_schedule_id' => $monday->id, 'session_date' => '2026-11-09', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $held = ClassSession::query()->create(['class_group_id' => $group->id, 'class_schedule_id' => $wednesday->id, 'session_date' => '2026-11-11', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'held', 'attendance_state' => 'closed']);
        $student = $this->student($association, $host, $art, 'REG-1');
        AttendanceRecord::query()->create(['class_session_id' => $held->id, 'student_id' => $student->id, 'status' => 'present', 'recorded_at' => now(), 'recorded_by_user_id' => $user->id]);

        $this->actingAs($user)->post(route('associations.classes.schedules.replace-set', [$association, $group]), [
            'valid_from' => '2026-11-01',
            'schedules' => [2 => ['enabled' => '1', 'weekday' => 2, 'start_time' => '19:00', 'end_time' => '20:00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $planned->fresh()->status);
        $this->assertSame('held', $held->fresh()->status);
        $this->assertDatabaseHas('attendance', ['class_session_id' => $held->id, 'student_id' => $student->id]);
        $newSession = $group->sessions()->whereDate('session_date', '2026-11-03')->where('status', 'planned')->firstOrFail();
        $this->assertSame('19:00', substr($newSession->start_time, 0, 5));
        $this->assertSame(0, app(SessionGenerator::class)->generate(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-19'), $association->id));
        $this->assertDatabaseHas('class_sessions', ['class_schedule_id' => $monday->id, 'session_date' => '2026-10-19']);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'class_schedule.set_replaced', 'association_id' => $association->id]);
    }

    public function test_visitor_from_another_dojo_is_free_idempotent_and_appears_in_attendance(): void
    {
        [$association, $user, $host, $art] = $this->context(['visitor.manage', 'attendance.manage', 'attendance.reopen']);
        $origin = Dojo::query()->create(['association_id' => $association->id, 'code' => 'ORIGEM', 'name' => 'Dojo origem']);
        $group = $this->group($association, $host, $art, 10);
        $session = $this->classSession($group);
        $regular = $this->student($association, $host, $art, 'REGULAR');
        $visitor = $this->student($association, $origin, $art, 'VISITANTE');
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $regular->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        $financialBefore = Charge::query()->count();

        $this->actingAs($user)->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'visitante'])->assertSessionHasNoErrors();
        $this->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'VISITANTE'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('class_session_visitors', 1);
        $this->assertDatabaseCount('class_enrollments', 1);
        $this->assertSame($financialBefore, Charge::query()->count());

        // A chamada só abre no dia da aula.
        $this->travelTo($session->session_date->setTime(18, 30));
        $this->post(route('associations.attendance.store', [$association, $session]), ['records' => [
            ['student_id' => $regular->id, 'status' => 'present'],
            ['student_id' => $visitor->id, 'status' => 'present'],
        ], 'close_attendance' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance', ['class_session_id' => $session->id, 'student_id' => $regular->id, 'participation_type' => 'regular']);
        $this->assertDatabaseHas('attendance', ['class_session_id' => $session->id, 'student_id' => $visitor->id, 'participation_type' => 'visitor']);
        $this->assertDatabaseHas('class_session_visitors', ['student_id' => $visitor->id, 'status' => 'attended']);

        $this->post(route('associations.attendance.reopen', [$association, $session]), ['reason' => 'Correção da presença visitante.'])->assertSessionHasNoErrors();
        $this->post(route('associations.attendance.store', [$association, $session]), ['records' => [
            ['student_id' => $regular->id, 'status' => 'present'],
            ['student_id' => $visitor->id, 'status' => 'absent'],
        ], 'close_attendance' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('class_session_visitors', ['student_id' => $visitor->id, 'status' => 'no_show']);
    }

    public function test_foreign_unknown_and_regular_students_cannot_be_added_as_visitors(): void
    {
        [$association, $user, $host, $art] = $this->context(['visitor.manage']);
        $group = $this->group($association, $host, $art);
        $session = $this->classSession($group);
        $regular = $this->student($association, $host, $art, 'REGULAR');
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $regular->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        $otherAssociation = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $otherDojo = Dojo::query()->create(['association_id' => $otherAssociation->id, 'code' => 'OUT', 'name' => 'Outro']);
        $otherArt = MartialArt::query()->create(['association_id' => $otherAssociation->id, 'name' => 'Judô']);
        $this->student($otherAssociation, $otherDojo, $otherArt, 'EXTERNO');

        $this->actingAs($user)->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'EXTERNO'])->assertSessionHasErrors('registration_number');
        $this->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'REGULAR'])->assertSessionHasErrors('registration_number');
        $this->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'INEXISTENTE'])->assertSessionHasErrors('registration_number');
        $this->assertDatabaseCount('class_session_visitors', 0);
    }

    public function test_user_without_visitor_permission_cannot_add_one(): void
    {
        [$association, , $host, $art] = $this->context([]);
        $group = $this->group($association, $host, $art);
        $session = $this->classSession($group);
        $visitor = $this->student($association, $host, $art, 'VISITOR');
        $unprivileged = User::factory()->create(['association_id' => $association->id]);

        $this->actingAs($unprivileged)->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => $visitor->registration_number])->assertForbidden();
        $this->assertDatabaseCount('class_session_visitors', 0);
    }

    public function test_designated_local_teacher_can_add_visitor_only_to_own_session(): void
    {
        [$association, , $host, $art] = $this->context([]);
        $teacherPerson = Person::query()->create(['full_name' => 'Professor local', 'birth_date' => '1990-01-01']);
        $association->people()->attach($teacherPerson, ['role_type' => 'teacher', 'start_date' => '2026-01-01', 'status' => 'active']);
        $teacher = TeacherProfile::query()->create(['person_id' => $teacherPerson->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        DojoTeacherAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $host->id, 'teacher_profile_id' => $teacher->id, 'start_date' => today(), 'created_by_user_id' => User::factory()->create()->id]);
        $teacherUser = User::factory()->create(['association_id' => $association->id, 'person_id' => $teacherPerson->id]);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Professor local', 'code' => 'local_'.uniqid(), 'scope_type' => 'teacher']);
        $permissions = collect(['dojo.access', 'session.view', 'visitor.manage'])->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role->permissions()->sync($permissions->pluck('id'));
        DojoRoleAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $host->id, 'user_id' => $teacherUser->id, 'role_id' => $role->id, 'start_date' => today(), 'created_by_user_id' => $teacherUser->id]);
        $ownGroup = $this->group($association, $host, $art);
        $ownGroup->update(['main_teacher_id' => $teacher->id]);
        $ownSession = $this->classSession($ownGroup);
        $otherSession = $this->classSession($this->group($association, $host, $art));
        $visitor = $this->student($association, $host, $art, 'VISITA-LOCAL');

        $this->actingAs($teacherUser)->post(route('operation.visitors.store', [$host, $ownSession]), ['registration_number' => $visitor->registration_number])->assertSessionHasNoErrors();
        $this->post(route('operation.visitors.store', [$host, $otherSession]), ['registration_number' => $visitor->registration_number])->assertNotFound();
        $this->assertDatabaseCount('class_session_visitors', 1);
    }

    public function test_minor_visitor_requires_guardian_and_capacity_is_enforced(): void
    {
        [$association, $user, $host, $art] = $this->context(['visitor.manage']);
        $association->update(['requires_minor_guardian' => true]);
        $group = $this->group($association, $host, $art, 1);
        $group->update(['minimum_age' => 5, 'maximum_age' => 17]);
        $session = $this->classSession($group);
        $minor = $this->student($association, $host, $art, 'MENOR', '2015-01-01');

        $this->actingAs($user)->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'MENOR'])->assertSessionHasErrors('registration_number');
        $guardian = Person::query()->create(['full_name' => 'Responsável']);
        $minor->guardians()->create(['guardian_person_id' => $guardian->id, 'relationship_type' => 'responsible', 'is_legal_guardian' => true, 'start_date' => '2026-01-01']);
        $this->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'MENOR'])->assertSessionHasNoErrors();
        $second = $this->student($association, $host, $art, 'MENOR-2', '2014-01-01');
        $second->guardians()->create(['guardian_person_id' => $guardian->id, 'relationship_type' => 'responsible', 'is_legal_guardian' => true, 'start_date' => '2026-01-01']);
        $this->post(route('associations.attendance.visitors.store', [$association, $session]), ['registration_number' => 'MENOR-2'])->assertSessionHasErrors('visitor');
        $this->assertDatabaseCount('class_session_visitors', 1);
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $host = Dojo::query()->create(['association_id' => $association->id, 'code' => 'HOST', 'name' => 'Dojo anfitrião']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor', 'code' => 'sprint18_'.uniqid(), 'scope_type' => 'association']);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $user, $host, $art];
    }

    private function group(Association $association, Dojo $dojo, MartialArt $art, ?int $capacity = null): ClassGroup
    {
        return ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Turma '.uniqid(), 'capacity' => $capacity, 'minimum_age' => 5, 'maximum_age' => 80, 'start_date' => '2026-01-01', 'status' => 'active']);
    }

    private function classSession(ClassGroup $group): ClassSession
    {
        return ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-11-10', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
    }

    private function student(Association $association, Dojo $dojo, MartialArt $art, string $registration, string $birthDate = '2000-01-01'): Student
    {
        $person = Person::query()->create(['full_name' => $registration, 'birth_date' => $birthDate]);
        $association->people()->attach($person, ['role_type' => 'student', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $student->martialArts()->create(['martial_art_id' => $art->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return $student;
    }
}
