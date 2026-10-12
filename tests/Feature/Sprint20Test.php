<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\FutureSessionMaintainer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Sprint20Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_group_generates_eight_chronological_sessions_across_weekdays(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        [$association, $dojo, $art, $user] = $this->context(['class.manage']);

        $this->actingAs($user)->post(route('associations.classes.store', $association), [
            'dojo_id' => $dojo->id,
            'martial_art_id' => $art->id,
            'name' => 'Judô noturno',
            'start_date' => '2026-10-01',
            'future_session_target' => 8,
            'schedules' => [
                ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00'],
                ['weekday' => 3, 'start_time' => '19:00', 'end_time' => '20:00'],
            ],
        ])->assertSessionHasNoErrors();

        $group = ClassGroup::query()->firstOrFail();
        $this->assertSame(8, $group->sessions()->count());
        $this->assertSame([
            '2026-10-12 18:00', '2026-10-14 19:00', '2026-10-19 18:00', '2026-10-21 19:00',
            '2026-10-26 18:00', '2026-10-28 19:00', '2026-11-02 18:00', '2026-11-04 19:00',
        ], $group->sessions()->orderBy('session_date')->orderBy('start_time')->get()
            ->map(fn ($session) => $session->session_date->format('Y-m-d').' '.substr($session->start_time, 0, 5))->all());
    }

    public function test_reexecution_is_idempotent_and_lowering_target_does_not_delete_sessions(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        [$association, $dojo, $art] = $this->context();
        $group = $this->group($association, $dojo, $art, 8);
        $this->schedule($group, 1);
        $maintainer = app(FutureSessionMaintainer::class);

        $this->assertSame(8, $maintainer->maintain($group)['created']);
        $this->assertSame(0, $maintainer->maintain($group)['created']);
        $result = $maintainer->updateTarget($group, 4);

        $this->assertSame(0, $result['created']);
        $this->assertSame(8, $group->sessions()->count());
        $this->assertSame(4, $group->fresh()->future_session_target);
    }

    public function test_cancelled_occurrence_is_preserved_and_replenished_without_resurrection(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        [$association, $dojo, $art, $user] = $this->context(['session.manage']);
        $group = $this->group($association, $dojo, $art, 4);
        $this->schedule($group, 1);
        app(FutureSessionMaintainer::class)->maintain($group);
        $cancelled = $group->sessions()->orderBy('session_date')->firstOrFail();

        $this->actingAs($user)->post(route('associations.sessions.cancel', [$association, $cancelled]), [
            'reason' => 'Dojo indisponível nesta data.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame(4, $group->sessions()->where('status', 'planned')->count());
        $this->assertSame(5, $group->sessions()->count());
        $this->assertSame(1, $group->sessions()->whereDate('session_date', $cancelled->session_date)->where('start_time', $cancelled->start_time)->count());
    }

    public function test_past_open_session_does_not_block_replenishment_and_group_end_is_respected(): void
    {
        Carbon::setTestNow('2026-10-14 20:00:00');
        [$association, $dojo, $art] = $this->context();
        $group = $this->group($association, $dojo, $art, 8, '2026-10-19');
        $schedule = $this->schedule($group, 1);
        $group->sessions()->create([
            'class_schedule_id' => $schedule->id,
            'session_date' => '2026-10-12',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'status' => 'planned',
            'attendance_state' => 'open',
        ]);

        $result = app(FutureSessionMaintainer::class)->maintain($group);

        $this->assertSame(1, $result['created']);
        $this->assertSame(7, $result['missing']);
        $this->assertDatabaseHas('class_sessions', ['class_group_id' => $group->id, 'session_date' => '2026-10-19']);
        $this->assertSame(2, $group->sessions()->count());
    }

    public function test_manual_refresh_requires_permission_and_keeps_association_scope(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        [$association, $dojo, $art] = $this->context();
        $group = $this->group($association, $dojo, $art, 8);
        $this->schedule($group, 1);
        $withoutPermission = User::factory()->create(['association_id' => $association->id]);

        $this->actingAs($withoutPermission)->post(route('associations.classes.sessions.refresh', [$association, $group]), [
            'future_session_target' => 4,
        ])->assertForbidden();

        [$otherAssociation, , , $manager] = $this->context(['class.manage'], 'OUTRA');
        $this->actingAs($manager)->post(route('associations.classes.sessions.refresh', [$otherAssociation, $group]), [
            'future_session_target' => 4,
        ])->assertNotFound();
        $this->assertSame(8, $group->fresh()->future_session_target);
    }

    public function test_hourly_command_uses_target_and_records_operational_result(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        [$association, $dojo, $art] = $this->context();
        $group = $this->group($association, $dojo, $art, 4);
        $this->schedule($group, 1);

        $this->artisan('sigam:sessions:generate', ['--association' => $association->id])->assertSuccessful();

        $this->assertSame(4, $group->sessions()->count());
        $this->assertDatabaseHas('scheduled_job_runs', [
            'job_name' => 'MaintainFutureClassSessions',
            'status' => 'completed',
            'processed_count' => 4,
        ]);
    }

    /** @return array{Association, Dojo, MartialArt, User} */
    private function context(array $permissions = [], string $code = 'BASE'): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.$code, 'trade_name' => 'Associação '.$code]);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => $code, 'name' => 'Dojo '.$code]);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô '.$code]);
        $user = User::factory()->create(['association_id' => $association->id]);
        if ($permissions !== []) {
            $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor '.$code, 'code' => 's20_'.strtolower($code).'_'.uniqid(), 'scope_type' => 'association']);
            $permissionModels = collect($permissions)->map(fn ($permission) => Permission::query()->firstOrCreate(['code' => $permission], ['description' => $permission]));
            $role->permissions()->sync($permissionModels->pluck('id'));
            $user->roles()->attach($role, ['association_id' => $association->id]);
        }

        return [$association, $dojo, $art, $user];
    }

    private function group(Association $association, Dojo $dojo, MartialArt $art, int $target, ?string $endDate = null): ClassGroup
    {
        return ClassGroup::query()->create([
            'association_id' => $association->id,
            'dojo_id' => $dojo->id,
            'martial_art_id' => $art->id,
            'name' => 'Turma '.uniqid(),
            'future_session_target' => $target,
            'start_date' => '2026-01-01',
            'end_date' => $endDate,
            'status' => 'active',
        ]);
    }

    private function schedule(ClassGroup $group, int $weekday): ClassSchedule
    {
        return $group->schedules()->create([
            'weekday' => $weekday,
            'start_time' => '18:00',
            'end_time' => '19:00',
            'valid_from' => '2026-01-01',
            'status' => 'active',
        ]);
    }
}
