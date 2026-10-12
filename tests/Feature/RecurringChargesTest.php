<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Charge;
use App\Models\Discount;
use App\Models\Dojo;
use App\Models\FinancialPlan;
use App\Models\FinancialSetting;
use App\Models\GratuityRequest;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\User;
use App\Services\Finance\OverdueChargeService;
use App\Services\Finance\RecurringChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringChargesTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_is_idempotent_and_preserves_complete_snapshot(): void
    {
        [$association, $student, , $plan, $link, $user] = $this->context('monthly', '100.00', 31);
        FinancialSetting::query()->create(['association_id' => $association->id, 'weekend_policy' => 'next_business_day', 'holiday_dates' => ['2027-03-01']]);
        $discount = Discount::query()->create(['student_id' => $student->id, 'student_plan_id' => $link->id, 'discount_type' => 'fixed', 'discount_value' => '20.00', 'reason' => 'Convênio', 'start_date' => '2027-02-01', 'end_date' => '2027-02-28', 'requested_by' => $user->id, 'requested_at' => now(), 'approved_by' => $user->id, 'approved_at' => now(), 'status' => 'active']);
        $service = app(RecurringChargeService::class);

        $this->assertSame(1, $service->generate(CarbonImmutable::parse('2027-02-01'), $association->id));
        $this->assertSame(0, $service->generate(CarbonImmutable::parse('2027-02-01'), $association->id));
        $charge = Charge::query()->firstOrFail();
        $plan->update(['amount' => '200.00']);
        $discount->update(['discount_value' => '5.00']);
        FinancialSetting::query()->where('association_id', $association->id)->update(['weekend_policy' => 'none', 'holiday_dates' => null]);

        $charge->refresh();
        $this->assertSame('100.00', $charge->original_amount);
        $this->assertSame('20.00', $charge->discount_amount);
        $this->assertSame('80.00', $charge->final_amount);
        $this->assertSame('2027-03-02', $charge->due_date->format('Y-m-d'));
        $this->assertSame('20.00', $charge->calculation_snapshot['discount']['value']);
        $this->assertSame('next_business_day', $charge->due_policy_snapshot['weekend_policy']);
        $this->assertDatabaseCount('charges', 1);
    }

    public function test_approved_gratuity_generates_waived_charge(): void
    {
        [$association, $student, $dojo, , , $user] = $this->context();
        GratuityRequest::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'student_id' => $student->id, 'reason' => 'Projeto social', 'justification' => 'Gratuidade aprovada em duas etapas.', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'requested_by' => $user->id, 'requested_at' => now(), 'dojo_approver_id' => $user->id, 'dojo_decision' => 'approved', 'dojo_decision_at' => now(), 'association_approver_id' => $user->id, 'association_decision' => 'approved', 'association_decision_at' => now(), 'status' => 'approved']);

        $this->assertSame(1, app(RecurringChargeService::class)->generate(CarbonImmutable::parse('2026-06-01'), $association->id));
        $charge = Charge::query()->firstOrFail();
        $this->assertSame('waived', $charge->status);
        $this->assertSame('0.00', $charge->final_amount);
        $this->assertSame('0.00', $charge->balance_amount);
        $this->assertNotNull($charge->gratuity_request_id);
    }

    public function test_semiannual_plan_uses_start_month_as_recurrence_anchor(): void
    {
        [$association] = $this->context('semiannual');
        $service = app(RecurringChargeService::class);

        $this->assertSame(0, $service->generate(CarbonImmutable::parse('2026-02-01'), $association->id));
        $this->assertSame(1, $service->generate(CarbonImmutable::parse('2026-07-01'), $association->id));
        $this->assertSame('2026-07-01', Charge::query()->sole()->competence->format('Y-m-d'));
    }

    public function test_overdue_job_changes_only_open_charges(): void
    {
        [$association] = $this->context();
        $generator = app(RecurringChargeService::class);
        $generator->generate(CarbonImmutable::parse('2026-01-01'), $association->id);
        $open = Charge::query()->firstOrFail();
        $paid = $open->replicate()->fill(['source_id' => $open->source_id + 100, 'status' => 'paid', 'paid_amount' => $open->final_amount, 'balance_amount' => '0.00']);
        $paid->save();
        $waived = $open->replicate()->fill(['source_id' => $open->source_id + 200, 'status' => 'waived', 'final_amount' => '0.00', 'balance_amount' => '0.00']);
        $waived->save();

        $this->assertSame(1, app(OverdueChargeService::class)->mark(CarbonImmutable::parse('2026-02-01'), $association->id));
        $this->assertSame('overdue', $open->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('waived', $waived->fresh()->status);
    }

    public function test_command_records_execution_and_statement_is_scoped(): void
    {
        [$association, $student] = $this->context();
        $this->artisan('sigam:charges:generate', ['--competence' => '2026-03', '--association' => $association->id])->assertSuccessful();
        $this->assertDatabaseHas('scheduled_job_runs', ['job_name' => 'GenerateRecurringCharges', 'status' => 'completed', 'processed_count' => 1]);
        $user = $this->user($association, ['charge.view']);
        $this->actingAs($user)->get(route('associations.charges.statement', [$association, $student]))->assertOk()->assertSee('Extrato financeiro');

        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $this->get(route('associations.charges.statement', [$other, $student]))->assertForbidden();
    }

    private function context(string $period = 'monthly', string $amount = '100.00', int $dueDay = 10): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.uniqid(), 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D1', 'name' => 'Dojo']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $person = Person::query()->create(['full_name' => 'Aluno', 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'A1', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $student->martialArts()->create(['martial_art_id' => $art->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        $plan = FinancialPlan::query()->create(['association_id' => $association->id, 'name' => 'Plano', 'billing_period' => $period, 'amount' => $amount, 'valid_from' => '2026-01-01', 'status' => 'active']);
        $link = StudentPlan::query()->create(['student_id' => $student->id, 'dojo_id' => $dojo->id, 'financial_plan_id' => $plan->id, 'start_date' => '2026-01-01', 'base_amount' => $amount, 'preferred_due_day' => $dueDay, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $association->id]);

        return [$association, $student, $dojo, $plan, $link, $user];
    }

    private function user(Association $association, array $codes): User
    {
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Cobranças', 'code' => 'charges_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }
}
