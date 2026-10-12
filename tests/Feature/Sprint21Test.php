<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\Charge;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\FinancialPlan;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDojoHistory;
use App\Models\StudentMartialArt;
use App\Models\StudentPlan;
use App\Models\StudentRankHistory;
use App\Models\User;
use App\Services\Finance\RecurringChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Sprint21Test extends TestCase
{
    use RefreshDatabase;

    public function test_local_plan_and_charge_keep_explicit_dojo_origin_even_when_primary_changes(): void
    {
        [$association, $dojoA, $dojoB, $user] = $this->organization();
        $student = $this->student($association, $dojoA, 'Aluno local');
        StudentDojoHistory::query()->create(['student_id' => $student->id, 'dojo_id' => $dojoB->id, 'start_date' => today()->subYear(), 'is_primary' => false]);
        $student->update(['primary_dojo_id' => $dojoB->id]);
        $plan = FinancialPlan::query()->create(['association_id' => $association->id, 'name' => 'Mensalidade geral', 'billing_period' => 'monthly', 'amount' => '120.00', 'valid_from' => today()->startOfMonth(), 'status' => 'active']);

        $this->actingAs($user)->post(route('operation.finance.plans.store', $dojoA), [
            'student_id' => $student->id, 'financial_plan_id' => $plan->id, 'start_date' => today()->startOfMonth()->format('Y-m-d'),
            'preferred_due_day' => 10,
        ])->assertRedirect();
        $link = StudentPlan::query()->sole();
        $this->assertSame($dojoA->id, $link->dojo_id);

        app(RecurringChargeService::class)->generate(CarbonImmutable::now()->startOfMonth(), $association->id, $user->id);
        $this->assertSame($dojoA->id, Charge::query()->sole()->dojo_id);
        $this->actingAs($user)->get(route('operation.finance.index', $dojoA))->assertOk()->assertSee('Mensalidade geral');
        $this->get(route('operation.finance.index', $dojoB))->assertForbidden();
    }

    public function test_dojo_registers_only_its_regular_student_and_never_lists_other_dojo_registration(): void
    {
        [$association, $dojoA, $dojoB, $user] = $this->organization();
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô', 'status' => 'active']);
        $rank = Rank::query()->create(['martial_art_id' => $art->id, 'name' => 'branca', 'display_name' => 'Faixa branca', 'sort_order' => 1, 'status' => 'active']);
        $studentA = $this->student($association, $dojoA, 'Atleta do Norte', $art, $rank);
        $studentB = $this->student($association, $dojoB, 'Atleta confidencial', $art, $rank);
        $championship = Championship::query()->create([
            'association_id' => $association->id, 'martial_art_id' => $art->id, 'name' => 'Copa da Associação',
            'championship_type' => 'open', 'organizer' => 'Associação', 'location_name' => 'Ginásio',
            'start_date' => today()->addMonth(), 'end_date' => today()->addMonth(),
            'registration_open_at' => now()->subDay(), 'registration_close_at' => now()->addWeek(),
            'default_fee' => '50.00', 'allow_competitor' => true, 'status' => 'open', 'created_by' => $user->id,
        ]);
        ChampionshipRegistration::query()->create([
            'association_id' => $association->id, 'championship_id' => $championship->id, 'person_id' => $studentB->person_id,
            'student_id' => $studentB->id, 'dojo_id' => $dojoB->id, 'participation_type' => 'competitor', 'category' => 'Adulto',
            'weight' => '70.00', 'rank_id' => $rank->id, 'registration_fee' => '50.00', 'status' => 'pending', 'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('operation.championships.register', [$dojoA, $championship]), [
            'student_id' => $studentA->id, 'category' => 'Adulto -73 kg', 'weight' => '72.50', 'rank_id' => $rank->id,
            'team_name' => $dojoA->name,
        ])->assertRedirect();
        $this->assertDatabaseHas('championship_registrations', ['championship_id' => $championship->id, 'student_id' => $studentA->id, 'dojo_id' => $dojoA->id]);
        $this->assertDatabaseHas('championship_dojo_participations', ['championship_id' => $championship->id, 'dojo_id' => $dojoA->id, 'status' => 'active']);
        $this->get(route('operation.championships.index', $dojoA))->assertOk()->assertSee('Copa da Associação')->assertSee('1 inscrição(ões) deste dojo');
        $this->get(route('operation.championships.show', [$dojoA, $championship]))->assertOk()->assertSee('Atleta do Norte')->assertDontSee('Atleta confidencial');

        $this->post(route('operation.championships.register', [$dojoA, $championship]), [
            'student_id' => $studentB->id, 'category' => 'Adulto', 'weight' => '70.00', 'rank_id' => $rank->id,
        ])->assertSessionHasErrors('student_id');
    }

    public function test_origin_migration_is_dry_run_and_idempotent_without_reassigning_history(): void
    {
        [$association, $dojoA, , $user] = $this->organization();
        $student = $this->student($association, $dojoA, 'Aluno legado');
        $plan = FinancialPlan::query()->create(['association_id' => $association->id, 'dojo_id' => $dojoA->id, 'name' => 'Plano local', 'billing_period' => 'monthly', 'amount' => '80.00', 'valid_from' => today()->subYear(), 'status' => 'active']);
        $link = StudentPlan::query()->create(['student_id' => $student->id, 'financial_plan_id' => $plan->id, 'start_date' => today()->subYear(), 'base_amount' => '80.00', 'preferred_due_day' => 10, 'status' => 'active']);
        Charge::query()->create(['association_id' => $association->id, 'student_id' => $student->id, 'person_id' => $student->person_id, 'source_type' => 'monthly_fee', 'source_id' => $link->id, 'description' => 'Histórica', 'competence' => today()->subMonth()->startOfMonth(), 'issued_at' => now(), 'due_date' => today(), 'original_amount' => '80.00', 'discount_amount' => '0.00', 'final_amount' => '80.00', 'paid_amount' => '0.00', 'balance_amount' => '80.00', 'calculation_snapshot' => [], 'due_policy_snapshot' => [], 'status' => 'open', 'created_by' => $user->id]);

        $this->artisan('sigam:operation:audit-origins', ['--association' => $association->id])->assertSuccessful();
        $this->assertNull($link->fresh()->dojo_id);
        $this->artisan('sigam:operation:audit-origins', ['--association' => $association->id, '--apply' => true])->assertSuccessful();
        $this->assertSame($dojoA->id, $link->fresh()->dojo_id);
        $this->assertNull(Charge::query()->sole()->dojo_id);
        $this->artisan('sigam:operation:audit-origins', ['--association' => $association->id, '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('student_plans', 1);
    }

    private function organization(): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $dojoA = Dojo::query()->create(['association_id' => $association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $dojoB = Dojo::query()->create(['association_id' => $association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $permissions = collect(['dojo.access', 'student.view', 'charge.view', 'student_plan.manage', 'payment.manage', 'gratuity.request', 'gratuity.approve_dojo', 'championship.view', 'championship.register'])
            ->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor local', 'code' => 'local', 'scope_type' => 'dojo']);
        $role->permissions()->sync($permissions);
        $user = User::factory()->create(['association_id' => $association->id]);
        DojoRoleAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $dojoA->id, 'user_id' => $user->id, 'role_id' => $role->id, 'start_date' => today()->subDay(), 'created_by_user_id' => $user->id]);

        return [$association, $dojoA, $dojoB, $user];
    }

    private function student(Association $association, Dojo $dojo, string $name, ?MartialArt $art = null, ?Rank $rank = null): Student
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => today()->subYear(), 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => uniqid('A'), 'entry_date' => today()->subYear(), 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        StudentDojoHistory::query()->create(['student_id' => $student->id, 'dojo_id' => $dojo->id, 'start_date' => today()->subYear(), 'is_primary' => true]);
        if ($art && $rank) {
            $link = StudentMartialArt::query()->create(['student_id' => $student->id, 'martial_art_id' => $art->id, 'start_date' => today()->subYear(), 'status' => 'active', 'current_rank_id' => $rank->id]);
            StudentRankHistory::query()->create(['student_martial_art_id' => $link->id, 'rank_id' => $rank->id, 'achieved_at' => today()->subYear(), 'source_type' => 'initial_registration']);
        }

        return $student;
    }
}
