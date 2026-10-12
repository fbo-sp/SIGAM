<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\FinancialSetting;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDojoHistory;
use App\Models\User;
use App\Services\Finance\FinancialBenefitService;
use App\Services\Finance\FinancialCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialPlansAndBenefitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_plan_preserves_amount_snapshot_and_rejects_overlap(): void
    {
        [$association, $student] = $this->context();
        $service = app(FinancialBenefitService::class);
        $plan = $service->createPlan($association, $this->planData('100.00'));
        $link = $service->assignPlan($association, $this->linkData($student, $plan->id));
        $plan->update(['amount' => '150.00']);

        $this->assertSame('100.00', $link->fresh()->base_amount);
        $this->expectException(ValidationException::class);
        $service->assignPlan($association, [...$this->linkData($student, $plan->id), 'start_date' => '2026-06-01']);
    }

    public function test_discount_requires_a_different_approver_and_only_applies_during_validity(): void
    {
        [$association, $student] = $this->context();
        $requester = $this->user($association, ['discount.manage']);
        $approver = $this->user($association, ['discount.approve']);
        $service = app(FinancialBenefitService::class);
        $plan = $service->createPlan($association, $this->planData('100.00'));
        $link = $service->assignPlan($association, $this->linkData($student, $plan->id));
        $this->actingAs($requester);
        $discount = $service->requestDiscount($association, $requester, ['student_id' => $student->id, 'student_plan_id' => $link->id, 'discount_type' => 'percentage', 'discount_value' => '20.00', 'reason' => 'Convênio', 'start_date' => '2026-02-01', 'end_date' => '2026-02-28']);
        $this->assertSame('pending', $discount->status);
        try {
            $service->decideDiscount($association, $discount, $requester, ['decision' => 'approved']);
            $this->fail('Aprovação própria deveria ter sido recusada.');
        } catch (ValidationException) {
            $this->assertSame('pending', $discount->fresh()->status);
        }
        $this->actingAs($approver);
        $service->decideDiscount($association, $discount, $approver, ['decision' => 'approved']);
        $calculator = app(FinancialCalculator::class);

        $this->assertSame('80.00', $calculator->simulate($student, CarbonImmutable::parse('2026-02-01'))['final_amount']);
        $this->assertSame('100.00', $calculator->simulate($student, CarbonImmutable::parse('2026-03-01'))['final_amount']);
    }

    public function test_gratuity_follows_two_distinct_approval_stages(): void
    {
        [$association, $student, $dojo] = $this->context();
        $requester = $this->user($association, ['gratuity.request']);
        $dojoApprover = $this->user($association, ['gratuity.approve_dojo']);
        $associationApprover = $this->user($association, ['gratuity.approve_association']);
        $service = app(FinancialBenefitService::class);
        $plan = $service->createPlan($association, $this->planData('120.00'));
        $service->assignPlan($association, $this->linkData($student, $plan->id));
        $this->actingAs($requester);
        $gratuity = $service->requestGratuity($association, $requester, ['student_id' => $student->id, 'dojo_id' => $dojo->id, 'reason' => 'Projeto social', 'justification' => 'Participação comprovada no projeto social.', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->actingAs($dojoApprover);
        $service->decideDojo($association, $gratuity, $dojoApprover, ['decision' => 'approved']);
        $this->assertSame('awaiting_association', $gratuity->fresh()->status);
        try {
            $service->decideAssociation($association, $gratuity, $dojoApprover, ['decision' => 'approved']);
            $this->fail('A mesma pessoa não pode realizar as duas aprovações.');
        } catch (ValidationException) {
            $this->assertSame('awaiting_association', $gratuity->fresh()->status);
        }
        $this->actingAs($associationApprover);
        $service->decideAssociation($association, $gratuity, $associationApprover, ['decision' => 'approved']);
        $simulation = app(FinancialCalculator::class)->simulate($student, CarbonImmutable::parse('2026-06-01'));

        $this->assertSame('approved', $gratuity->fresh()->status);
        $this->assertSame('0.00', $simulation['final_amount']);
        $this->assertSame($gratuity->id, $simulation['gratuity_request_id']);
    }

    public function test_due_day_is_clamped_and_weekend_policy_is_applied(): void
    {
        [$association, $student] = $this->context();
        FinancialSetting::query()->create(['association_id' => $association->id, 'weekend_policy' => 'next_business_day', 'holiday_dates' => ['2027-03-01']]);
        $service = app(FinancialBenefitService::class);
        $plan = $service->createPlan($association, $this->planData('90.00'));
        $service->assignPlan($association, [...$this->linkData($student, $plan->id), 'preferred_due_day' => 31]);

        $this->assertSame('2027-03-02', app(FinancialCalculator::class)->simulate($student, CarbonImmutable::parse('2027-02-01'))['due_date']);
    }

    public function test_financial_page_requires_permission_in_the_same_association(): void
    {
        [$association] = $this->context();
        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $user = $this->user($other, ['finance.view']);

        $this->actingAs($user)->get(route('associations.finance.index', $association))->assertForbidden();
    }

    private function context(): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D1', 'name' => 'Dojo']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $person = Person::query()->create(['full_name' => 'Aluno', 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'A1', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        StudentDojoHistory::query()->create(['student_id' => $student->id, 'dojo_id' => $dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $student->martialArts()->create(['martial_art_id' => $art->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return [$association, $student, $dojo, $art];
    }

    private function user(Association $association, array $codes): User
    {
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Financeiro', 'code' => 'finance_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }

    private function planData(string $amount): array
    {
        return ['name' => 'Mensalidade', 'billing_period' => 'monthly', 'amount' => $amount, 'valid_from' => '2026-01-01', 'valid_until' => null];
    }

    private function linkData(Student $student, int $planId): array
    {
        return ['student_id' => $student->id, 'dojo_id' => $student->primary_dojo_id, 'financial_plan_id' => $planId, 'start_date' => '2026-01-01', 'end_date' => null, 'preferred_due_day' => 10];
    }
}
