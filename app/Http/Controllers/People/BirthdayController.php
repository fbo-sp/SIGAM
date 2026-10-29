<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Dojo;
use App\Models\Student;
use App\Services\People\BirthdayService;
use App\Services\Portal\PortalAccessService;
use App\Services\Reporting\ReportContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/** Tela Aniversariantes nos três níveis, fechar o aviso do dia no portal e esconder o próprio aniversário. */
class BirthdayController extends Controller
{
    /** Cookie com a data em que o aviso do dia foi fechado neste navegador. */
    public const DISMISS_COOKIE = 'judoma_bday_dismissed';

    public function __construct(private readonly BirthdayService $birthdays) {}

    public function association(Request $request, Association $association): View
    {
        return $this->index($request, $this->birthdays->association($request->user(), $association));
    }

    public function dojo(Request $request, Dojo $dojo): View
    {
        return $this->index($request, $this->birthdays->dojo($request->user(), $dojo));
    }

    public function teacher(Request $request): View
    {
        return $this->index($request, $this->birthdays->teacher($request->user()));
    }

    /** O × do aviso vale até o fim do dia neste navegador; no próximo aniversário o aviso volta. */
    public function dismiss(Request $request): RedirectResponse
    {
        Cookie::queue(self::DISMISS_COOKIE, today()->toDateString(), (int) ceil(now()->diffInMinutes(today()->endOfDay())) + 1);

        return back();
    }

    public function privacy(Request $request, Student $student, PortalAccessService $access): RedirectResponse
    {
        $student = $access->resolveStudent($request->user(), $student);
        abort_unless($access->canEditContact($request->user(), $student), 403);
        $hidden = $request->validate(['hide_birthday' => ['required', 'boolean']])['hide_birthday'];
        $this->birthdays->setHidden($request->user(), $student->loadMissing('person'), (bool) $hidden);

        return back()->with('status', $hidden ? 'Aniversário escondido dos colegas.' : 'Aniversário visível para os colegas.');
    }

    private function index(Request $request, ?ReportContext $context): View
    {
        abort_if($context === null, 403);
        $validated = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
            'dojo' => ['nullable', 'integer'],
            'turma' => ['nullable', 'integer'],
            'mostrar' => ['nullable', 'in:'.implode(',', array_keys(BirthdayService::SHOW))],
        ]);
        $month = isset($validated['mes']) ? Carbon::createFromFormat('Y-m-d', $validated['mes'].'-01')->startOfDay() : today()->startOfMonth();
        // Filtros só restringem dentro do alcance; um id de fora volta para "todos".
        $dojoId = $context->dojos->contains('id', (int) ($validated['dojo'] ?? 0)) && $context->level === 'association' ? (int) $validated['dojo'] : null;
        $classId = $context->classes->contains('id', (int) ($validated['turma'] ?? 0)) ? (int) $validated['turma'] : null;
        $filters = ['dojo_id' => $dojoId, 'class_group_id' => $classId, 'show' => $validated['mostrar'] ?? 'todos'];
        $rows = $this->birthdays->month($context, $month->year, $month->month, $filters);
        $months = collect(range(1, 12))->map(fn (int $number) => Carbon::create($month->year, $number, 1));

        return view('birthdays.index', [
            'context' => $context,
            'rows' => $rows,
            'month' => $month,
            'months' => $months,
            'filters' => $filters,
            'action' => match ($context->level) {
                'association' => route('associations.birthdays', $context->association),
                'dojo' => route('operation.birthdays', $context->dojo),
                default => route('teacher.birthdays'),
            },
        ]);
    }
}
