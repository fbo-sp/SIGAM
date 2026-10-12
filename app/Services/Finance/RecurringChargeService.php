<?php

namespace App\Services\Finance;

use App\Models\Charge;
use App\Models\StudentPlan;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecurringChargeService
{
    public function __construct(private readonly FinancialCalculator $calculator, private readonly AuditService $audit) {}

    public function generate(CarbonImmutable $competence, ?int $associationId = null, ?int $createdBy = null): int
    {
        $competence = $competence->startOfMonth();
        $created = 0;
        StudentPlan::query()
            ->where('student_plans.status', 'active')
            ->whereNotNull('student_plans.dojo_id')
            ->whereDate('start_date', '<=', $competence)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $competence))
            ->whereHas('student', fn ($query) => $query->where('status', 'active')->when($associationId, fn ($scope) => $scope->where('association_id', $associationId)))
            ->whereHas('financialPlan', fn ($query) => $query->where('status', 'active')->whereDate('valid_from', '<=', $competence)->where(fn ($validity) => $validity->whereNull('valid_until')->orWhereDate('valid_until', '>=', $competence)))
            ->with(['student.person', 'financialPlan'])
            ->orderBy('id')
            ->chunkById(100, function ($plans) use ($competence, $createdBy, &$created): void {
                foreach ($plans as $plan) {
                    if (! $this->isDueInCompetence($plan, $competence)) {
                        continue;
                    }
                    $wasCreated = DB::transaction(function () use ($plan, $competence, $createdBy): bool {
                        $locked = StudentPlan::query()->lockForUpdate()->findOrFail($plan->id);
                        if (Charge::query()->where('source_type', 'monthly_fee')->where('source_id', $locked->id)->whereDate('competence', $competence)->exists()) {
                            return false;
                        }
                        $locked->load(['student.person', 'financialPlan']);
                        $calculation = $this->calculator->calculate($locked, $competence);
                        $waived = bccomp($calculation['final_amount'], '0.00', 2) === 0;
                        $charge = Charge::query()->create([
                            'association_id' => $locked->student->association_id,
                            'dojo_id' => $locked->dojo_id,
                            'student_id' => $locked->student_id,
                            'person_id' => $locked->student->person_id,
                            'source_type' => 'monthly_fee',
                            'source_id' => $locked->id,
                            'description' => 'Mensalidade '.$locked->financialPlan->name.' — '.$competence->format('m/Y'),
                            'competence' => $competence->format('Y-m-d'),
                            'issued_at' => now(),
                            'due_date' => $calculation['due_date'],
                            'original_amount' => $calculation['base_amount'],
                            'discount_amount' => $calculation['discount_amount'],
                            'final_amount' => $calculation['final_amount'],
                            'paid_amount' => '0.00',
                            'balance_amount' => $calculation['final_amount'],
                            'discount_id' => $calculation['discount_id'],
                            'gratuity_request_id' => $calculation['gratuity_request_id'],
                            'calculation_snapshot' => $calculation,
                            'due_policy_snapshot' => $calculation['due_policy'],
                            'status' => $waived ? 'waived' : 'open',
                            'created_by' => $createdBy,
                        ]);
                        $this->audit->record('charge.generated', 'create', $charge, null, ['source_type' => 'monthly_fee', 'source_id' => $locked->id, 'competence' => $competence->format('Y-m-d'), 'final_amount' => $charge->final_amount, 'status' => $charge->status]);

                        return true;
                    });
                    if ($wasCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    private function isDueInCompetence(StudentPlan $plan, CarbonImmutable $competence): bool
    {
        $frequency = ['monthly' => 1, 'semiannual' => 6, 'annual' => 12][$plan->financialPlan->billing_period] ?? null;
        if ($frequency === null) {
            return false;
        }
        $start = CarbonImmutable::parse($plan->start_date)->startOfMonth();
        $months = (($competence->year - $start->year) * 12) + ($competence->month - $start->month);

        return $months >= 0 && $months % $frequency === 0;
    }
}
