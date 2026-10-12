<?php

namespace App\Services\Finance;

use App\Models\Association;
use App\Models\Discount;
use App\Models\Dojo;
use App\Models\FinancialPlan;
use App\Models\FinancialSetting;
use App\Models\GratuityRequest;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialBenefitService
{
    public function __construct(private readonly AuditService $audit) {}

    public function updateSettings(Association $association, array $data): FinancialSetting
    {
        $settings = FinancialSetting::query()->updateOrCreate(['association_id' => $association->id], $data);
        $this->audit->record('financial_settings.updated', 'update', $settings, null, ['weekend_policy' => $settings->weekend_policy, 'discounts_require_approval' => $settings->discounts_require_approval]);

        return $settings;
    }

    public function createPlan(Association $association, array $data): FinancialPlan
    {
        if (! empty($data['dojo_id']) && ! $association->dojos()->whereKey($data['dojo_id'])->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['dojo_id' => 'Selecione um dojo ativo desta associação.']);
        }
        if (! empty($data['martial_art_id']) && ! MartialArt::query()->whereKey($data['martial_art_id'])->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->exists()) {
            throw ValidationException::withMessages(['martial_art_id' => 'Selecione uma modalidade ativa desta associação.']);
        }
        $plan = FinancialPlan::query()->create([...$data, 'association_id' => $association->id, 'status' => 'active']);
        $this->audit->record('financial_plan.created', 'create', $plan, null, ['amount' => $plan->amount, 'billing_period' => $plan->billing_period]);

        return $plan;
    }

    public function assignPlan(Association $association, array $data): StudentPlan
    {
        return DB::transaction(function () use ($association, $data): StudentPlan {
            $student = Student::query()->where('association_id', $association->id)->where('status', 'active')->lockForUpdate()->find($data['student_id']);
            $plan = FinancialPlan::query()->where('association_id', $association->id)->where('status', 'active')->find($data['financial_plan_id']);
            if (! $student) {
                throw ValidationException::withMessages(['student_id' => 'Selecione um aluno ativo desta associação.']);
            }
            if (! $plan) {
                throw ValidationException::withMessages(['financial_plan_id' => 'Selecione um plano ativo desta associação.']);
            }
            $dojo = Dojo::query()->where('association_id', $association->id)->where('status', 'active')->find($data['dojo_id'] ?? null);
            if (! $dojo) {
                throw ValidationException::withMessages(['dojo_id' => 'Selecione um dojo ativo desta associação.']);
            }
            $hasRegularLink = $student->dojoHistory()->where('dojo_id', $dojo->id)
                ->whereDate('start_date', '<=', $data['start_date'])
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $data['start_date']))->exists();
            if (! $hasRegularLink) {
                throw ValidationException::withMessages(['student_id' => 'O aluno precisa ter vínculo regular com o dojo na data de início.']);
            }
            if ($plan->dojo_id && $plan->dojo_id !== $dojo->id) {
                throw ValidationException::withMessages(['financial_plan_id' => 'Este plano é exclusivo de outro dojo.']);
            }
            if ($plan->martial_art_id && ! $student->martialArts()->where('martial_art_id', $plan->martial_art_id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['financial_plan_id' => 'O aluno não pratica a modalidade deste plano.']);
            }
            if ($data['start_date'] < $plan->valid_from->format('Y-m-d') || ($plan->valid_until && $data['start_date'] > $plan->valid_until->format('Y-m-d'))) {
                throw ValidationException::withMessages(['start_date' => 'O início deve estar dentro da vigência do plano.']);
            }
            if ($plan->valid_until && (empty($data['end_date']) || $data['end_date'] > $plan->valid_until->format('Y-m-d'))) {
                throw ValidationException::withMessages(['end_date' => 'Informe um término dentro da vigência deste plano.']);
            }
            if ($this->overlaps(StudentPlan::query()->where('student_id', $student->id)->where('dojo_id', $dojo->id)->where('status', 'active'), $data['start_date'], $data['end_date'] ?? null)) {
                throw ValidationException::withMessages(['start_date' => 'O aluno já possui outro plano vigente neste dojo e período.']);
            }

            $link = StudentPlan::query()->create([...$data, 'base_amount' => $plan->amount, 'status' => 'active']);
            $this->audit->record('student_plan.assigned', 'create', $link, null, ['financial_plan_id' => $plan->id, 'base_amount' => $link->base_amount], ['association_id' => $association->id]);

            return $link;
        });
    }

    public function requestDiscount(Association $association, User $user, array $data): Discount
    {
        return DB::transaction(function () use ($association, $user, $data): Discount {
            $student = Student::query()->where('association_id', $association->id)->lockForUpdate()->find($data['student_id']);
            if (! $student) {
                throw ValidationException::withMessages(['student_id' => 'Selecione um aluno desta associação.']);
            }
            $plan = isset($data['student_plan_id']) ? StudentPlan::query()->where('student_id', $student->id)->find($data['student_plan_id']) : null;
            if (isset($data['student_plan_id']) && ! $plan) {
                throw ValidationException::withMessages(['student_plan_id' => 'O vínculo de plano não pertence ao aluno.']);
            }
            if ($data['discount_type'] === 'percentage' && bccomp($data['discount_value'], '100', 2) === 1) {
                throw ValidationException::withMessages(['discount_value' => 'O percentual não pode ultrapassar 100%.']);
            }
            $query = Discount::query()->where('student_id', $student->id)->whereIn('status', ['pending', 'active']);
            if ($plan) {
                $query->where('student_plan_id', $plan->id);
            }
            if ($this->overlaps($query, $data['start_date'], $data['end_date'])) {
                throw ValidationException::withMessages(['start_date' => 'Já existe desconto pendente ou ativo nesse período.']);
            }
            $settings = FinancialSetting::query()->firstOrCreate(['association_id' => $association->id]);
            $status = $settings->discounts_require_approval ? 'pending' : 'active';
            $discount = Discount::query()->create([...$data, 'requested_by' => $user->id, 'requested_at' => now(), 'status' => $status, 'approved_by' => $status === 'active' ? $user->id : null, 'approved_at' => $status === 'active' ? now() : null]);
            $this->audit->record('discount.requested', 'create', $discount, null, ['type' => $discount->discount_type, 'value' => $discount->discount_value, 'status' => $status], ['association_id' => $association->id]);

            return $discount;
        });
    }

    public function decideDiscount(Association $association, Discount $discount, User $user, array $data): Discount
    {
        return DB::transaction(function () use ($association, $discount, $user, $data): Discount {
            $locked = Discount::query()->lockForUpdate()->findOrFail($discount->id);
            $this->ensureStudentScope($association, $locked->student_id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Somente descontos pendentes podem receber decisão.']);
            }
            if ($locked->requested_by === $user->id) {
                throw ValidationException::withMessages(['decision' => 'O solicitante não pode aprovar o próprio desconto.']);
            }
            $status = $data['decision'] === 'approved' ? 'active' : 'rejected';
            $locked->update(['approved_by' => $user->id, 'approved_at' => now(), 'decision_notes' => $data['notes'] ?? null, 'status' => $status]);
            $this->audit->record('discount.decided', 'update', $locked, ['status' => 'pending'], ['status' => $status], ['association_id' => $association->id]);

            return $locked;
        });
    }

    public function requestGratuity(Association $association, User $user, array $data): GratuityRequest
    {
        return DB::transaction(function () use ($association, $user, $data): GratuityRequest {
            $student = Student::query()->where('association_id', $association->id)->where('status', 'active')->lockForUpdate()->find($data['student_id']);
            $hasRegularLink = $student?->dojoHistory()->where('dojo_id', $data['dojo_id'])
                ->whereDate('start_date', '<=', $data['start_date'])
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $data['start_date']))->exists();
            if (! $student || ! $hasRegularLink) {
                throw ValidationException::withMessages(['student_id' => 'O aluno ativo deve pertencer ao dojo selecionado.']);
            }
            if ($this->overlaps(GratuityRequest::query()->where('student_id', $student->id)->whereIn('status', ['awaiting_dojo', 'awaiting_association', 'approved']), $data['start_date'], $data['end_date'])) {
                throw ValidationException::withMessages(['start_date' => 'Já existe gratuidade em análise ou aprovada nesse período.']);
            }
            $request = GratuityRequest::query()->create([...$data, 'association_id' => $association->id, 'requested_by' => $user->id, 'requested_at' => now(), 'status' => 'awaiting_dojo']);
            $this->audit->record('gratuity.requested', 'create', $request, null, ['status' => 'awaiting_dojo'], ['association_id' => $association->id]);

            return $request;
        });
    }

    public function decideDojo(Association $association, GratuityRequest $request, User $user, array $data): GratuityRequest
    {
        return $this->decideGratuity($association, $request, $user, $data, 'dojo');
    }

    public function decideAssociation(Association $association, GratuityRequest $request, User $user, array $data): GratuityRequest
    {
        return $this->decideGratuity($association, $request, $user, $data, 'association');
    }

    private function decideGratuity(Association $association, GratuityRequest $request, User $user, array $data, string $stage): GratuityRequest
    {
        return DB::transaction(function () use ($association, $request, $user, $data, $stage): GratuityRequest {
            $locked = GratuityRequest::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($request->id);
            $expected = $stage === 'dojo' ? 'awaiting_dojo' : 'awaiting_association';
            if ($locked->status !== $expected) {
                throw ValidationException::withMessages(['decision' => 'Esta solicitação não está na etapa esperada.']);
            }
            if ($stage === 'association' && $locked->dojo_approver_id === $user->id) {
                throw ValidationException::withMessages(['decision' => 'A aprovação da associação deve ser feita por outra pessoa.']);
            }
            $approved = $data['decision'] === 'approved';
            $updates = $stage === 'dojo'
                ? ['dojo_approver_id' => $user->id, 'dojo_decision' => $data['decision'], 'dojo_decision_at' => now(), 'dojo_notes' => $data['notes'] ?? null, 'status' => $approved ? 'awaiting_association' : 'rejected']
                : ['association_approver_id' => $user->id, 'association_decision' => $data['decision'], 'association_decision_at' => now(), 'association_notes' => $data['notes'] ?? null, 'status' => $approved ? 'approved' : 'rejected'];
            $locked->update($updates);
            $this->audit->record('gratuity.'.$stage.'_decided', 'update', $locked, ['status' => $expected], ['status' => $locked->status], ['association_id' => $association->id]);

            return $locked;
        });
    }

    private function overlaps($query, string $start, ?string $end): bool
    {
        return $query->whereDate('start_date', '<=', $end ?? '9999-12-31')->where(fn ($nested) => $nested->whereNull('end_date')->orWhereDate('end_date', '>=', $start))->exists();
    }

    private function ensureStudentScope(Association $association, int $studentId): void
    {
        abort_unless(Student::query()->where('association_id', $association->id)->whereKey($studentId)->exists(), 404);
    }
}
