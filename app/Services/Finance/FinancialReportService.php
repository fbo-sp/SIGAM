<?php

namespace App\Services\Finance;

use App\Models\Association;
use App\Models\Charge;
use App\Models\FinancialPlan;
use App\Models\Payment;
use App\Models\StudentPlan;
use Illuminate\Support\Collection;

class FinancialReportService
{
    public function build(Association $association, string $from, string $to, ?int $dojoId = null): array
    {
        $charges = Charge::query()
            ->with(['dojo', 'student.person'])
            ->where('association_id', $association->id)
            ->whereBetween('due_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->when($dojoId, fn ($query) => $query->where('dojo_id', $dojoId))
            ->orderBy('due_date')
            ->get();

        $payments = Payment::query()
            ->with(['charge.dojo', 'charge.student.person', 'creator'])
            ->where('association_id', $association->id)
            ->where('status', 'valid')
            ->whereBetween('payment_date', [$from, $to])
            ->whereHas('charge', fn ($query) => $query->when($dojoId, fn ($query) => $query->where('dojo_id', $dojoId)))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $outstanding = $charges->whereIn('status', ['open', 'overdue', 'partially_paid']);
        $overdue = $outstanding->filter(fn (Charge $charge) => $charge->due_date->lt(today()));

        return [
            'metrics' => [
                'expected' => $this->sum($charges, 'final_amount'),
                'receivable' => $this->sum($outstanding, 'balance_amount'),
                'overdue' => $this->sum($overdue, 'balance_amount'),
                'received' => $this->sum($payments, 'amount'),
                'discounts' => $this->sum($charges, 'discount_amount'),
                'gratuities' => $this->sum($charges->where('status', 'waived'), 'original_amount'),
            ],
            'counts' => ['charges' => $charges->count(), 'payments' => $payments->count(), 'overdue' => $overdue->count(), 'gratuities' => $charges->where('status', 'waived')->count()],
            'by_dojo' => $this->byDojo($charges, $payments),
            'by_modality' => $this->byModality($charges, $payments),
            'charges' => $charges,
            'payments' => $payments,
        ];
    }

    private function byDojo(Collection $charges, Collection $payments): Collection
    {
        $rows = collect();
        foreach ($charges as $charge) {
            $key = (string) ($charge->dojo_id ?? 0);
            $row = $rows->get($key, ['name' => $charge->dojo?->name ?? 'Sem dojo', 'expected' => '0.00', 'received' => '0.00', 'receivable' => '0.00']);
            $row['expected'] = bcadd($row['expected'], $charge->final_amount, 2);
            $row['receivable'] = bcadd($row['receivable'], $charge->balance_amount, 2);
            $rows->put($key, $row);
        }
        foreach ($payments as $payment) {
            $key = (string) ($payment->charge->dojo_id ?? 0);
            $row = $rows->get($key, ['name' => $payment->charge->dojo?->name ?? 'Sem dojo', 'expected' => '0.00', 'received' => '0.00', 'receivable' => '0.00']);
            $row['received'] = bcadd($row['received'], $payment->amount, 2);
            $rows->put($key, $row);
        }

        return $rows->sortBy('name')->values();
    }

    private function byModality(Collection $charges, Collection $payments): Collection
    {
        $sourceIds = $charges->where('source_type', 'monthly_fee')->pluck('source_id')
            ->merge($payments->pluck('charge')->where('source_type', 'monthly_fee')->pluck('source_id'))->filter()->unique();
        $planIds = StudentPlan::query()->whereIn('id', $sourceIds)->pluck('financial_plan_id', 'id');
        $names = FinancialPlan::query()->with('martialArt')->whereIn('id', $planIds->values())->get()->mapWithKeys(fn ($plan) => [$plan->id => $plan->martialArt?->name ?? 'Sem modalidade específica']);
        $rows = collect();
        $add = function (Charge $charge, string $field, string $amount) use ($planIds, $names, $rows): void {
            $name = $charge->source_type === 'monthly_fee' ? ($names->get($planIds->get($charge->source_id)) ?? 'Sem modalidade específica') : 'Outras receitas';
            $row = $rows->get($name, ['name' => $name, 'expected' => '0.00', 'received' => '0.00']);
            $row[$field] = bcadd($row[$field], $amount, 2);
            $rows->put($name, $row);
        };
        foreach ($charges as $charge) {
            $add($charge, 'expected', $charge->final_amount);
        }
        foreach ($payments as $payment) {
            $add($payment->charge, 'received', $payment->amount);
        }

        return $rows->sortBy('name')->values();
    }

    private function sum(Collection $items, string $field): string
    {
        return $items->reduce(fn (string $total, $item) => bcadd($total, (string) $item->{$field}, 2), '0.00');
    }
}
