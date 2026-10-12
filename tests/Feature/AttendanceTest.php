<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\SessionGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_generation_is_idempotent_and_uses_schedule_version(): void
    {
        [$association, , $group] = $this->context([]);
        ClassSchedule::query()->create(['class_group_id' => $group->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-08-01', 'status' => 'active']);
        $generator = app(SessionGenerator::class);

        $this->assertSame(1, $generator->generate(Carbon::parse('2026-08-31'), Carbon::parse('2026-08-31'), $association->id));
        $this->assertSame(0, $generator->generate(Carbon::parse('2026-08-31'), Carbon::parse('2026-08-31'), $association->id));
        $this->assertDatabaseCount('class_sessions', 1);
    }

    public function test_complete_attendance_can_be_closed_but_not_edited_until_reopened(): void
    {
        [$association, $user, $group, $student] = $this->context(['attendance.manage', 'attendance.reopen']);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-08-31', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $payload = ['records' => [['student_id' => $student->id, 'status' => 'present']], 'close_attendance' => '1'];

        $this->actingAs($user)->post(route('associations.attendance.store', [$association, $session]), $payload)->assertSessionHasNoErrors();
        $this->assertSame('closed', $session->fresh()->attendance_state);
        $this->assertSame('held', $session->fresh()->status);
        $this->post(route('associations.attendance.store', [$association, $session]), $payload)->assertSessionHasErrors('attendance');

        $this->post(route('associations.attendance.reopen', [$association, $session]), ['reason' => 'Correção solicitada pelo professor.'])->assertSessionHasNoErrors();
        $this->assertSame('open', $session->fresh()->attendance_state);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $association->id, 'event_type' => 'attendance.reopened']);
    }

    public function test_attendance_cannot_include_student_outside_roster(): void
    {
        [$association, $user, $group] = $this->context(['attendance.manage']);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-08-31', 'start_time' => '18:00', 'end_time' => '19:00']);
        $outsider = $this->student($association, $group->dojo, $group->martialArt, 'OUT');

        $this->actingAs($user)->post(route('associations.attendance.store', [$association, $session]), ['records' => [['student_id' => $outsider->id, 'status' => 'present']]])->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_rescheduling_preserves_original_session(): void
    {
        [$association, $user, $group] = $this->context(['session.manage']);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-08-31', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned']);

        $this->actingAs($user)->post(route('associations.sessions.reschedule', [$association, $session]), ['session_date' => '2026-09-01', 'start_time' => '19:00', 'end_time' => '20:00', 'reason' => 'Indisponibilidade do local'])->assertSessionHasNoErrors();

        $this->assertSame('rescheduled', $session->fresh()->status);
        $new = ClassSession::query()->where('rescheduled_from_id', $session->id)->firstOrFail();
        $this->assertSame('2026-09-01', $new->session_date->format('Y-m-d'));
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D', 'name' => 'Dojo']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $group = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Turma', 'start_date' => '2026-01-01', 'status' => 'active']);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->create(['code' => $code, 'description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Professor', 'code' => 'attendance_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);
        $student = $this->student($association, $dojo, $art, 'A1');
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return [$association, $user, $group, $student];
    }

    private function student(Association $association, Dojo $dojo, MartialArt $art, string $registration): Student
    {
        $person = Person::query()->create(['full_name' => $registration, 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);

        return $student;
    }
}
