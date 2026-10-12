<?php

namespace App\Services\Finance;

use App\Models\Discount;
use App\Models\FinancialSetting;
use App\Models\GratuityRequest;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Support\Money\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class FinancialCalculator
{
    public function simulate(Student $student, CarbonImmutable $competence): array
    {
        $plan = StudentPlan::query()->where('student_id', $student->id)->where('status', 'active')->whereDate('start_date', '<=', $competence)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $competence))->with('financialPlan')->latest('start_date')->first();
        if (! $plan) {
            throw ValidationException::withMessages(['student_id' => 'O aluno não possui plano vigente nessa competência.']);
        }

        return $this->calculate($plan, $competence);
    }

    public function calculate(StudentPlan $plan, CarbonImmutable $competence): array
    {
        $plan->loadMissing(['student', 'financialPlan']);
        $student = $plan->student;

        $settings = FinancialSetting::query()->firstOrCreate(['association_id' => $student->association_id]);
        $dueDate = $this->dueDate($competence, $plan->preferred_due_day, $settings->weekend_policy, $settings->holiday_dates ?? []);
        $gratuity = GratuityRequest::query()->where('student_id', $student->id)->where('dojo_id', $plan->dojo_id)->where('status', 'approved')->whereDate('start_date', '<=', $competence)->whereDate('end_date', '>=', $competence)->first();
        $discount = Discount::query()->where('student_id', $student->id)->where(fn ($query) => $query->whereNull('student_plan_id')->orWhere('student_plan_id', $plan->id))->where('status', 'active')->whereDate('start_date', '<=', $competence)->whereDate('end_date', '>=', $competence)->first();
        $base = $plan->base_amount;
        $discountAmount = '0.00';
        $explanation = ['Plano '.$plan->financialPlan->name.': R$ '.MoneyFormatter::brl($base)];

        if ($gratuity) {
            $discountAmount = $base;
            $explanation[] = 'Gratuidade aprovada #'.$gratuity->id.': isenção integral';
        } elseif ($discount) {
            $discountAmount = $discount->discount_type === 'percentage' ? bcround(bcdiv(bcmul($base, $discount->discount_value, 4), '100', 4), 2) : $discount->discount_value;
            if (bccomp($discountAmount, $base, 2) === 1) {
                $discountAmount = $base;
            }
            $explanation[] = 'Desconto '.$discount->reason.': R$ '.MoneyFormatter::brl($discountAmount);
        }
        $final = bcsub($base, $discountAmount, 2);

        return [
            'calculator_version' => 1,
            'student_plan_id' => $plan->id,
            'dojo_id' => $plan->dojo_id,
            'financial_plan' => ['id' => $plan->financialPlan->id, 'name' => $plan->financialPlan->name, 'billing_period' => $plan->financialPlan->billing_period],
            'base_amount' => $base,
            'discount_amount' => $discountAmount,
            'final_amount' => $final,
            'due_date' => $dueDate->format('Y-m-d'),
            'due_policy' => ['nonexistent_day_policy' => $settings->nonexistent_day_policy, 'weekend_policy' => $settings->weekend_policy, 'holiday_dates' => $settings->holiday_dates ?? [], 'preferred_due_day' => $plan->preferred_due_day],
            'gratuity_request_id' => $gratuity?->id,
            'gratuity' => $gratuity ? ['id' => $gratuity->id, 'reason' => $gratuity->reason, 'start_date' => $gratuity->start_date->format('Y-m-d'), 'end_date' => $gratuity->end_date->format('Y-m-d')] : null,
            'discount_id' => $discount?->id,
            'discount' => $discount ? ['id' => $discount->id, 'type' => $discount->discount_type, 'value' => $discount->discount_value, 'reason' => $discount->reason, 'start_date' => $discount->start_date->format('Y-m-d'), 'end_date' => $discount->end_date->format('Y-m-d')] : null,
            'explanation' => $explanation,
        ];
    }

    private function dueDate(CarbonImmutable $competence, int $preferredDay, string $weekendPolicy, array $holidays): CarbonImmutable
    {
        $date = $competence->startOfMonth()->day(min($preferredDay, $competence->daysInMonth));
        if ($weekendPolicy === 'next_business_day') {
            while ($date->isWeekend() || in_array($date->format('Y-m-d'), $holidays, true)) {
                $date = $date->addDay();
            }
        }
        if ($weekendPolicy === 'previous_business_day') {
            while ($date->isWeekend() || in_array($date->format('Y-m-d'), $holidays, true)) {
                $date = $date->subDay();
            }
        }

        return $date;
    }
}
