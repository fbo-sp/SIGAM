<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_is_created_with_versioned_initial_schedule(): void
    {
        [$association, $user, $dojo, $art] = $this->context(['class.view', 'class.manage']);

        $this->actingAs($user)->post(route('associations.classes.store', $association), $this->groupData($dojo, $art))->assertSessionHasNoErrors();

        $group = ClassGroup::query()->firstOrFail();
        $schedule = $group->schedules()->firstOrFail();
        $this->assertSame('2026-09-01', $schedule->valid_from->format('Y-m-d'));
        $this->assertSame('active', $schedule->status);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'class_group.created', 'association_id' => $association->id]);
    }

    public function test_replacing_schedule_closes_previous_version(): void
    {
        [$association, $user, $dojo, $art] = $this->context(['class.manage']);
        $group = $this->createGroup($association, $dojo, $art);
        $schedule = $group->schedules()->create(['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-09-01', 'status' => 'active']);

        $this->actingAs($user)->post(route('associations.classes.schedules.replace', [$association, $group, $schedule]), [
            'weekday' => 3, 'start_time' => '19:00', 'end_time' => '20:00', 'valid_from' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-30', $schedule->fresh()->valid_until->format('Y-m-d'));
        $this->assertSame('replaced', $schedule->fresh()->status);
        $newSchedule = $group->schedules()->where('status', 'active')->firstOrFail();
        $this->assertSame(3, $newSchedule->weekday);
        $this->assertSame('2026-10-01', $newSchedule->valid_from->format('Y-m-d'));
    }

    public function test_enrollment_checks_capacity_and_duplicate(): void
    {
        [$association, $user, $dojo, $art] = $this->context(['enrollment.manage']);
        $group = $this->createGroup($association, $dojo, $art, 1);
        $studentA = $this->student($association, $dojo, $art, 'A1', '2010-01-01');
        $studentB = $this->student($association, $dojo, $art, 'A2', '2011-01-01');

        $this->actingAs($user)->post(route('associations.classes.enrollments.store', [$association, $group]), ['student_id' => $studentA->id, 'start_date' => '2026-09-01'])->assertSessionHasNoErrors();
        $this->post(route('associations.classes.enrollments.store', [$association, $group]), ['student_id' => $studentA->id, 'start_date' => '2026-09-02'])->assertSessionHasErrors('student_id');
        $this->post(route('associations.classes.enrollments.store', [$association, $group]), ['student_id' => $studentB->id, 'start_date' => '2026-09-01'])->assertSessionHasErrors('student_id');

        $this->assertDatabaseCount('class_enrollments', 1);
    }

    public function test_student_outside_age_range_is_rejected(): void
    {
        [$association, $user, $dojo, $art] = $this->context(['enrollment.manage']);
        $group = $this->createGroup($association, $dojo, $art, 10, 7, 12);
        $adult = $this->student($association, $dojo, $art, 'ADULT', '1990-01-01');

        $this->actingAs($user)->post(route('associations.classes.enrollments.store', [$association, $group]), ['student_id' => $adult->id, 'start_date' => '2026-09-01'])->assertSessionHasErrors('student_id');
        $this->assertDatabaseCount('class_enrollments', 0);
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D1', 'name' => 'Dojo']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->create(['code' => $code, 'description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor', 'code' => 'class_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $user, $dojo, $art];
    }

    private function groupData(Dojo $dojo, MartialArt $art): array
    {
        return ['dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Judô Infantil', 'minimum_age' => 7, 'maximum_age' => 15, 'capacity' => 20, 'start_date' => '2026-09-01', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00'];
    }

    private function createGroup(Association $association, Dojo $dojo, MartialArt $art, int $capacity = 20, ?int $minimumAge = null, ?int $maximumAge = null): ClassGroup
    {
        return ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Turma '.uniqid(), 'capacity' => $capacity, 'minimum_age' => $minimumAge, 'maximum_age' => $maximumAge, 'start_date' => '2026-09-01', 'status' => 'active']);
    }

    private function student(Association $association, Dojo $dojo, MartialArt $art, string $registration, string $birthDate): Student
    {
        $person = Person::query()->create(['full_name' => $registration, 'birth_date' => $birthDate]);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $student->martialArts()->create(['martial_art_id' => $art->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return $student;
    }
}
