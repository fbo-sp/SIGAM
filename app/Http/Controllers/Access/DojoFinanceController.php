<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BenefitDecisionRequest;
use App\Http\Requests\Finance\PaymentRequest;
use App\Models\Charge;
use App\Models\Dojo;
use App\Models\FinancialPlan;
use App\Models\GratuityRequest;
use App\Models\StudentPlan;
use App\Services\Access\OperationalScope;
use App\Services\Finance\FinancialBenefitService;
use App\Services\Finance\PaymentService;
use Illuminate\Http\Request;

class DojoFinanceController extends Controller
{
    public function __construct(private readonly OperationalScope $scope) {}

    public function index(Request $request, Dojo $dojo)
    {
        $this->authorizeContext($request, $dojo, 'charge.view');
        $studentIds = $this->scope->students($request->user(), $dojo)->select('students.id');
        $students = $this->scope->students($request->user(), $dojo)->with('person:id,full_name')->orderBy('registration_number')->get();
        $plans = FinancialPlan::query()->where('association_id', $dojo->association_id)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('dojo_id')->orWhere('dojo_id', $dojo->id))->orderBy('name')->get();
        $studentPlans = StudentPlan::query()->where('dojo_id', $dojo->id)->whereIn('student_id', $studentIds)
            ->with(['student.person:id,full_name', 'financialPlan:id,name'])->latest()->limit(50)->get();
        $charges = Charge::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)
            ->with(['student.person:id,full_name', 'payments'])->latest('issued_at')->paginate(25);
        $gratuities = GratuityRequest::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)
            ->with('student.person:id,full_name')->latest()->limit(30)->get();
        $capabilities = collect(['student_plan.manage', 'payment.manage', 'gratuity.request', 'gratuity.approve_dojo'])
            ->mapWithKeys(fn ($permission) => [$permission => $this->scope->canDojo($request->user(), $dojo, $permission)]);

        return view('operation.finance', compact('dojo', 'students', 'plans', 'studentPlans', 'charges', 'gratuities', 'capabilities'));
    }

    public function assignPlan(Request $request, Dojo $dojo, FinancialBenefitService $service)
    {
        $this->authorizeContext($request, $dojo, 'student_plan.manage');
        $data = $request->validate([
            'student_id' => ['required', 'integer'], 'financial_plan_id' => ['required', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'preferred_due_day' => ['required', 'integer', 'between:1,31'],
        ]);
        abort_unless($this->scope->students($request->user(), $dojo)->whereKey($data['student_id'])->exists(), 404);
        $service->assignPlan($dojo->association, [...$data, 'dojo_id' => $dojo->id]);

        return back()->with('status', 'Plano vinculado a este dojo. As cobranças futuras manterão esta origem.');
    }

    public function pay(PaymentRequest $request, Dojo $dojo, Charge $charge, PaymentService $service)
    {
        $this->authorizeContext($request, $dojo, 'payment.manage');
        abort_unless($charge->association_id === $dojo->association_id && $charge->dojo_id === $dojo->id, 404);
        $service->post($dojo->association, $charge, $request->user(), $request->validated());

        return back()->with('status', 'Pagamento registrado e saldo atualizado.');
    }

    public function requestGratuity(Request $request, Dojo $dojo, FinancialBenefitService $service)
    {
        $this->authorizeContext($request, $dojo, 'gratuity.request');
        $data = $request->validate([
            'student_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:255'],
            'justification' => ['required', 'string', 'min:10', 'max:10000'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        abort_unless($this->scope->students($request->user(), $dojo)->whereKey($data['student_id'])->exists(), 404);
        $service->requestGratuity($dojo->association, $request->user(), [...$data, 'dojo_id' => $dojo->id]);

        return back()->with('status', 'Gratuidade enviada para aprovação do dojo.');
    }

    public function decideGratuity(BenefitDecisionRequest $request, Dojo $dojo, GratuityRequest $gratuity, FinancialBenefitService $service)
    {
        $this->authorizeContext($request, $dojo, 'gratuity.approve_dojo');
        abort_unless($gratuity->association_id === $dojo->association_id && $gratuity->dojo_id === $dojo->id, 404);
        $service->decideDojo($dojo->association, $gratuity, $request->user(), $request->validated());

        return back()->with('status', 'Decisão do dojo registrada. Se aprovada, a associação fará a análise final.');
    }

    private function authorizeContext(Request $request, Dojo $dojo, string $permission): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo), 403);
        abort_unless($this->scope->canDojo($request->user(), $dojo, $permission), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}
