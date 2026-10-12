<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Charge;
use App\Models\Dojo;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Notifications\NotificationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_full_and_idempotent_payments_reconcile_charge(): void
    {
        [$association, $charge, $user] = $this->context();
        $service = app(PaymentService::class);
        $key = (string) Str::uuid();

        $first = $service->post($association, $charge, $user, $this->payment($key, '40.00'));
        $replay = $service->post($association, $charge, $user, $this->payment($key, '40.00'));

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('partially_paid', $charge->fresh()->status);
        $this->assertSame('40.00', $charge->fresh()->paid_amount);
        $this->assertSame('60.00', $charge->fresh()->balance_amount);

        $service->post($association, $charge, $user, $this->payment((string) Str::uuid(), '60.00'));
        $this->assertSame('paid', $charge->fresh()->status);
        $this->assertSame('100.00', $charge->fresh()->paid_amount);
        $this->assertSame('0.00', $charge->fresh()->balance_amount);
    }

    public function test_overpayment_and_reusing_key_with_different_data_are_blocked(): void
    {
        [$association, $charge, $user] = $this->context();
        $service = app(PaymentService::class);
        $key = (string) Str::uuid();
        $service->post($association, $charge, $user, $this->payment($key, '40.00'));

        try {
            $service->post($association, $charge, $user, $this->payment($key, '41.00'));
            $this->fail('A mesma chave não pode aceitar dados diferentes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->post($association, $charge, $user, $this->payment((string) Str::uuid(), '60.01'));
    }

    public function test_reversal_preserves_history_and_restores_overdue_status(): void
    {
        [$association, $charge, $user] = $this->context(today()->subDay()->format('Y-m-d'));
        $service = app(PaymentService::class);
        $payment = $service->post($association, $charge, $user, $this->payment((string) Str::uuid(), '100.00'));

        $service->reverse($association, $payment, $user, 'Pagamento lançado na cobrança errada.');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'reversed', 'reversed_by' => $user->id]);
        $this->assertSame('overdue', $charge->fresh()->status);
        $this->assertSame('0.00', $charge->fresh()->paid_amount);
        $this->assertSame('100.00', $charge->fresh()->balance_amount);

        $this->expectException(ValidationException::class);
        $service->reverse($association, $payment, $user, 'Tentativa repetida de estorno.');
    }

    public function test_partially_paid_charge_past_due_counts_as_overdue(): void
    {
        [$association, $charge, $user] = $this->context(today()->subDays(3)->format('Y-m-d'));
        app(PaymentService::class)->post($association, $charge, $user, $this->payment((string) Str::uuid(), '30.00'));
        $this->assertSame('partially_paid', $charge->fresh()->status);

        $this->assertSame(1, Charge::query()->where('association_id', $association->id)->pastDue()->count());
        $this->assertSame(0, Charge::query()->where('association_id', $association->id)->notYetDue()->count());
        $this->assertSame(0, Charge::query()->where('association_id', $association->id)->pastDue(today()->subDays(4))->count());

        $viewer = $this->user($association, ['charge.view']);
        $this->actingAs($viewer)->get(route('associations.charges.index', $association))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['overdue_count'] === 1 && bccomp($summary['overdue_balance'], '70.00', 2) === 0 && $summary['open_count'] === 0);

        app(NotificationGenerator::class)->generate($association->id);
        $this->assertDatabaseHas('notification_events', ['association_id' => $association->id, 'event_type' => 'charge.overdue', 'idempotency_key' => 'charge-overdue-'.$charge->id]);
    }

    public function test_partially_paid_charge_within_term_counts_as_open(): void
    {
        [$association, $charge, $user] = $this->context(today()->addDays(10)->format('Y-m-d'));
        app(PaymentService::class)->post($association, $charge, $user, $this->payment((string) Str::uuid(), '30.00'));

        $viewer = $this->user($association, ['charge.view']);
        $this->actingAs($viewer)->get(route('associations.charges.index', $association))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['open_count'] === 1 && bccomp($summary['open_balance'], '70.00', 2) === 0 && $summary['overdue_count'] === 0);
    }

    public function test_payment_routes_validate_scope_and_financial_report_exports_csv(): void
    {
        [$association, $charge] = $this->context();
        $user = $this->user($association, ['charge.view', 'payment.manage', 'payment.reverse', 'finance.report']);
        $this->actingAs($user)->post(route('associations.payments.store', [$association, $charge]), $this->payment((string) Str::uuid(), '40,00'))->assertRedirect(route('associations.charges.show', [$association, $charge]));

        $query = ['from' => today()->startOfMonth()->format('Y-m-d'), 'to' => today()->endOfMonth()->format('Y-m-d')];
        $this->get(route('associations.finance.reports.index', [$association] + $query))->assertOk()->assertSee('R$ 100,00')->assertSee('R$ 40,00')->assertSee('R$ 60,00');
        $export = $this->get(route('associations.finance.reports.export', [$association] + $query));
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Pagamento', $export->streamedContent());

        [$otherAssociation, $otherCharge] = $this->context();
        $this->post(route('associations.payments.store', [$otherAssociation, $otherCharge]), $this->payment((string) Str::uuid(), '10.00'))->assertForbidden();
        $this->assertDatabaseMissing('payments', ['charge_id' => $otherCharge->id]);
    }

    private function context(?string $dueDate = null): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.uniqid(), 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D'.uniqid(), 'name' => 'Dojo Central']);
        $person = Person::query()->create(['full_name' => 'Aluno Teste', 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'A'.uniqid(), 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $charge = Charge::query()->create([
            'association_id' => $association->id, 'dojo_id' => $dojo->id, 'student_id' => $student->id, 'person_id' => $person->id,
            'source_type' => 'manual', 'source_id' => null, 'description' => 'Mensalidade de teste', 'competence' => today()->startOfMonth(),
            'issued_at' => now(), 'due_date' => $dueDate ?? today(), 'original_amount' => '100.00', 'discount_amount' => '0.00',
            'final_amount' => '100.00', 'paid_amount' => '0.00', 'balance_amount' => '100.00', 'calculation_snapshot' => ['explanation' => ['Valor de teste.']],
            'due_policy_snapshot' => ['weekend_policy' => 'none'], 'status' => $dueDate && $dueDate < today()->format('Y-m-d') ? 'overdue' : 'open', 'created_by' => $user->id,
        ]);

        return [$association, $charge, $user];
    }

    private function payment(string $key, string $amount): array
    {
        return ['idempotency_key' => $key, 'payment_date' => today()->format('Y-m-d'), 'amount' => $amount, 'payment_method' => 'pix', 'reference' => 'TX-1', 'notes' => null];
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
}
