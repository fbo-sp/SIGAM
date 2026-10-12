<?php

namespace App\Services\Finance;

use App\Models\Association;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function post(Association $association, Charge $charge, User $user, array $data): Payment
    {
        $hash = $this->requestHash($charge->id, $data);

        return DB::transaction(function () use ($association, $charge, $user, $data, $hash): Payment {
            $locked = Charge::query()->where('association_id', $association->id)->lockForUpdate()->find($charge->id);
            if (! $locked) {
                abort(404);
            }

            $existing = Payment::query()->where('association_id', $association->id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Esta chave já foi usada com dados diferentes. Atualize a página e tente novamente.']);
                }

                return $existing;
            }

            if (! in_array($locked->status, ['open', 'overdue', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['payment' => 'Esta cobrança não aceita novos pagamentos.']);
            }
            if (bccomp($data['amount'], '0.00', 2) !== 1) {
                throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
            }
            if (bccomp($data['amount'], $locked->balance_amount, 2) === 1) {
                throw ValidationException::withMessages(['amount' => 'O valor não pode ultrapassar o saldo da cobrança.']);
            }

            $payment = Payment::query()->create([...$data, 'association_id' => $association->id, 'charge_id' => $locked->id, 'request_hash' => $hash, 'posted_at' => now(), 'status' => 'valid', 'created_by' => $user->id]);
            $this->reconcileLocked($locked);
            $this->audit->record('payment.posted', 'create', $payment, null, ['charge_id' => $locked->id, 'amount' => $payment->amount, 'payment_method' => $payment->payment_method]);

            return $payment;
        }, 3);
    }

    public function reverse(Association $association, Payment $payment, User $user, string $reason): Payment
    {
        return DB::transaction(function () use ($association, $payment, $user, $reason): Payment {
            $locked = Payment::query()->where('association_id', $association->id)->lockForUpdate()->find($payment->id);
            if (! $locked) {
                abort(404);
            }
            $charge = Charge::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($locked->charge_id);
            if ($locked->status !== 'valid') {
                throw ValidationException::withMessages(['reversal_reason' => 'Este pagamento já foi estornado.']);
            }

            $locked->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $previous = ['paid_amount' => $charge->paid_amount, 'balance_amount' => $charge->balance_amount, 'status' => $charge->status];
            $this->reconcileLocked($charge);
            $this->audit->record('payment.reversed', 'update', $locked, ['status' => 'valid'], ['status' => 'reversed'], ['charge_id' => $charge->id, 'amount' => $locked->amount]);
            $this->audit->record('charge.reconciled_after_reversal', 'update', $charge, $previous, ['paid_amount' => $charge->paid_amount, 'balance_amount' => $charge->balance_amount, 'status' => $charge->status]);

            return $locked;
        }, 3);
    }

    public function reconcile(Association $association, Charge $charge): Charge
    {
        return DB::transaction(function () use ($association, $charge): Charge {
            $locked = Charge::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($charge->id);

            return $this->reconcileLocked($locked);
        });
    }

    private function reconcileLocked(Charge $charge): Charge
    {
        $paid = (string) Payment::query()->where('charge_id', $charge->id)->where('status', 'valid')->sum('amount');
        $paid = bcadd($paid, '0.00', 2);
        $balance = bcsub($charge->final_amount, $paid, 2);
        if (bccomp($balance, '0.00', 2) === -1) {
            throw ValidationException::withMessages(['amount' => 'Os pagamentos válidos ultrapassam o valor da cobrança.']);
        }
        $status = match (true) {
            bccomp($balance, '0.00', 2) === 0 => 'paid',
            bccomp($paid, '0.00', 2) === 1 => 'partially_paid',
            $charge->due_date->lt(today()) => 'overdue',
            default => 'open',
        };
        $charge->update(['paid_amount' => $paid, 'balance_amount' => $balance, 'status' => $status]);

        return $charge->refresh();
    }

    private function requestHash(int $chargeId, array $data): string
    {
        return hash('sha256', json_encode(['charge_id' => $chargeId, 'payment_date' => $data['payment_date'], 'amount' => bcadd($data['amount'], '0.00', 2), 'payment_method' => $data['payment_method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
