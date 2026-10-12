<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ChargeGenerationRequest;
use App\Models\Association;
use App\Models\Charge;
use App\Models\ScheduledJobRun;
use App\Models\Student;
use App\Services\Finance\RecurringChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChargeController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $base = Charge::query()->where('association_id', $association->id);
        $charges = (clone $base)->with(['student.person', 'dojo'])->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))->when($request->filled('competence'), fn ($query) => $query->whereDate('competence', $request->string('competence').'-01'))->orderBy('due_date')->orderBy('id')->paginate(30)->withQueryString();
        $summary = [
            'open_count' => (clone $base)->where('status', 'open')->count(),
            'open_balance' => (string) (clone $base)->where('status', 'open')->sum('balance_amount'),
            'overdue_count' => (clone $base)->where('status', 'overdue')->count(),
            'overdue_balance' => (string) (clone $base)->where('status', 'overdue')->sum('balance_amount'),
        ];
        $students = Student::query()->where('association_id', $association->id)->with('person')->orderBy('registration_number')->get();
        $jobRuns = ScheduledJobRun::query()->whereIn('job_name', ['GenerateRecurringCharges', 'MarkOverdueCharges'])->latest('id')->limit(6)->get();

        return view('finance.charges.index', compact('association', 'charges', 'summary', 'students', 'jobRuns'));
    }

    public function generate(ChargeGenerationRequest $request, Association $association, RecurringChargeService $service): RedirectResponse
    {
        $competence = CarbonImmutable::createFromFormat('!Y-m', $request->validated('competence'));
        $created = $service->generate($competence, $association->id, $request->user()->id);

        return back()->with('status', $created ? "{$created} cobrança(s) gerada(s)." : 'Nenhuma cobrança nova. A competência já estava processada ou não possui plano elegível.');
    }

    public function show(Association $association, Charge $charge): View
    {
        abort_unless($charge->association_id === $association->id, 404);
        $charge->load(['student.person', 'dojo', 'payments.creator', 'payments.reverser']);

        return view('finance.charges.show', compact('association', 'charge'));
    }

    public function statement(Association $association, Student $student): View
    {
        abort_unless($student->association_id === $association->id, 404);
        $student->load('person');
        $charges = Charge::query()->with(['payments' => fn ($query) => $query->latest('payment_date')->latest('id')])->where('association_id', $association->id)->where('student_id', $student->id)->orderByDesc('competence')->orderByDesc('id')->paginate(30);
        $balance = (string) Charge::query()->where('association_id', $association->id)->where('student_id', $student->id)->whereIn('status', ['open', 'overdue', 'partially_paid'])->sum('balance_amount');

        return view('finance.charges.statement', compact('association', 'student', 'charges', 'balance'));
    }
}
