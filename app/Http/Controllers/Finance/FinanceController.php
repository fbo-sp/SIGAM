<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BenefitDecisionRequest;
use App\Http\Requests\Finance\DiscountRequest;
use App\Http\Requests\Finance\FinancialPlanRequest;
use App\Http\Requests\Finance\FinancialSettingRequest;
use App\Http\Requests\Finance\GratuityRequestRequest;
use App\Http\Requests\Finance\StudentPlanRequest;
use App\Models\Association;
use App\Models\Discount;
use App\Models\FinancialPlan;
use App\Models\FinancialSetting;
use App\Models\GratuityRequest;
use App\Models\MartialArt;
use App\Models\Student;
use App\Services\Finance\FinancialBenefitService;
use App\Services\Finance\FinancialCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request, Association $association, FinancialCalculator $calculator): View
    {
        $settings = FinancialSetting::query()->firstOrCreate(['association_id' => $association->id]);
        $plans = FinancialPlan::query()->where('association_id', $association->id)->with(['dojo', 'martialArt'])->latest('valid_from')->get();
        $students = Student::query()->where('association_id', $association->id)->where('status', 'active')->with(['person', 'studentPlans.financialPlan'])->orderBy('registration_number')->get();
        $discounts = Discount::query()->whereHas('student', fn ($query) => $query->where('association_id', $association->id))->with('student.person')->latest()->limit(30)->get();
        $gratuities = GratuityRequest::query()->where('association_id', $association->id)->with(['student.person', 'dojo'])->latest()->limit(30)->get();
        $simulation = null;
        if ($request->boolean('simulate')) {
            $validated = $request->validate(['student_id' => ['required', 'integer'], 'competence' => ['required', 'date_format:Y-m']]);
            $student = Student::query()->where('association_id', $association->id)->findOrFail($validated['student_id']);
            $simulation = $calculator->simulate($student, CarbonImmutable::createFromFormat('Y-m-d', $validated['competence'].'-01')->startOfDay());
        }
        $dojos = $association->dojos()->where('status', 'active')->orderBy('name')->get();
        $martialArts = MartialArt::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->orderBy('name')->get();

        return view('finance.index', compact('association', 'settings', 'plans', 'students', 'discounts', 'gratuities', 'simulation', 'dojos', 'martialArts'));
    }

    public function updateSettings(FinancialSettingRequest $request, Association $association, FinancialBenefitService $service): RedirectResponse
    {
        $service->updateSettings($association, $request->validated());

        return back()->with('status', 'Regras financeiras atualizadas.');
    }

    public function storePlan(FinancialPlanRequest $request, Association $association, FinancialBenefitService $service): RedirectResponse
    {
        $service->createPlan($association, $request->validated());

        return back()->with('status', 'Plano financeiro criado.');
    }

    public function assignPlan(StudentPlanRequest $request, Association $association, FinancialBenefitService $service): RedirectResponse
    {
        $service->assignPlan($association, $request->validated());

        return back()->with('status', 'Plano vinculado com o valor-base preservado.');
    }

    public function storeDiscount(DiscountRequest $request, Association $association, FinancialBenefitService $service): RedirectResponse
    {
        $discount = $service->requestDiscount($association, $request->user(), $request->validated());

        return back()->with('status', $discount->status === 'active' ? 'Desconto ativado.' : 'Desconto enviado para aprovação.');
    }

    public function decideDiscount(BenefitDecisionRequest $request, Association $association, Discount $discount, FinancialBenefitService $service): RedirectResponse
    {
        $service->decideDiscount($association, $discount, $request->user(), $request->validated());

        return back()->with('status', 'Decisão do desconto registrada.');
    }

    public function storeGratuity(GratuityRequestRequest $request, Association $association, FinancialBenefitService $service): RedirectResponse
    {
        $service->requestGratuity($association, $request->user(), $request->validated());

        return back()->with('status', 'Gratuidade enviada para aprovação do dojo.');
    }

    public function decideDojo(BenefitDecisionRequest $request, Association $association, GratuityRequest $gratuity, FinancialBenefitService $service): RedirectResponse
    {
        $service->decideDojo($association, $gratuity, $request->user(), $request->validated());

        return back()->with('status', 'Decisão do dojo registrada.');
    }

    public function decideAssociation(BenefitDecisionRequest $request, Association $association, GratuityRequest $gratuity, FinancialBenefitService $service): RedirectResponse
    {
        $service->decideAssociation($association, $gratuity, $request->user(), $request->validated());

        return back()->with('status', 'Decisão da associação registrada.');
    }
}
