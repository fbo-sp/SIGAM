<?php

namespace App\Http\Controllers\Championship;

use App\Http\Controllers\Controller;
use App\Http\Requests\Championship\ChampionshipDecisionRequest;
use App\Http\Requests\Championship\ChampionshipRegistrationRequest;
use App\Http\Requests\Championship\ChampionshipRequest;
use App\Http\Requests\Championship\ChampionshipResultRequest;
use App\Models\Association;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\Dojo;
use App\Models\FederationMembership;
use App\Models\MartialArt;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Student;
use App\Services\Championship\ChampionshipRegistrationService;
use App\Services\Championship\ChampionshipResultService;
use App\Services\Championship\ChampionshipService;
use App\Support\Export\CsvWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChampionshipController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $championships = Championship::query()->where('association_id', $association->id)->with(['martialArt', 'dojo'])->withCount('registrations')->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->orderByDesc('start_date')->paginate(24)->withQueryString();

        return view('championship.index', compact('association', 'championships'));
    }

    public function create(Association $association): View
    {
        return view('championship.create', $this->catalogs($association));
    }

    public function store(ChampionshipRequest $request, Association $association, ChampionshipService $service): RedirectResponse
    {
        $championship = $service->create($association, $request->user(), $request->validated());

        return redirect()->route('associations.championships.show', [$association, $championship])->with('status', 'Campeonato criado.');
    }

    public function update(ChampionshipRequest $request, Association $association, Championship $championship, ChampionshipService $service): RedirectResponse
    {
        $service->update($association, $championship, $request->validated());

        return back()->with('status', 'Campeonato atualizado. Inscrições e cobranças existentes foram preservadas.');
    }

    public function show(Association $association, Championship $championship): View
    {
        $this->scope($association, $championship);
        $championship->load(['martialArt', 'dojo', 'registrations.person', 'registrations.student', 'registrations.dojo', 'registrations.rank', 'registrations.charge', 'registrations.result']);
        $registrations = $championship->registrations;
        $summary = ['total' => $registrations->count(), 'pending' => $registrations->where('status', 'pending')->count(), 'approved' => $registrations->where('status', 'approved')->count(), 'paid' => $registrations->filter(fn ($item) => $item->charge?->status === 'paid')->count(), 'medals' => $registrations->filter(fn ($item) => filled($item->result?->medal))->count(), 'points' => $registrations->reduce(fn (string $total, $item) => bcadd($total, (string) ($item->result?->points ?? '0.00'), 2), '0.00'), 'by_role' => $registrations->countBy('participation_type'), 'by_dojo' => $registrations->groupBy(fn ($item) => $item->dojo?->name ?? 'Sem dojo')->map->count()->sortKeys()];
        $catalogs = $this->catalogs($association);

        return view('championship.show', compact('association', 'championship', 'summary') + $catalogs);
    }

    public function register(ChampionshipRegistrationRequest $request, Association $association, Championship $championship, ChampionshipRegistrationService $service): RedirectResponse
    {
        $this->scope($association, $championship);
        $service->register($association, $championship, $request->user(), $request->validated());

        return back()->with('status', 'Inscrição enviada para aprovação. A taxa foi congelada para esta inscrição.');
    }

    public function decide(ChampionshipDecisionRequest $request, Association $association, ChampionshipRegistration $registration, ChampionshipRegistrationService $service): RedirectResponse
    {
        abort_unless($registration->association_id === $association->id, 404);
        $service->decide($association, $registration, $request->user(), $request->validated('decision'), $request->validated('decision_reason'));

        return back()->with('status', $request->validated('decision') === 'approve' ? 'Inscrição aprovada e cobrança gerada quando aplicável.' : 'Inscrição recusada com o motivo registrado.');
    }

    public function result(ChampionshipResultRequest $request, Association $association, ChampionshipRegistration $registration, ChampionshipResultService $service): RedirectResponse
    {
        abort_unless($registration->association_id === $association->id, 404);
        $service->record($association, $registration, $request->user(), $request->validated());

        return back()->with('status', 'Participação e resultado registrados no histórico esportivo.');
    }

    public function export(Association $association, Championship $championship): StreamedResponse
    {
        $this->scope($association, $championship);
        $championship->load(['registrations.person', 'registrations.dojo', 'registrations.charge', 'registrations.result']);

        return response()->streamDownload(function () use ($championship): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            CsvWriter::row($output, ['Pessoa', 'Papel', 'Dojo', 'Situação', 'Taxa', 'Pagamento', 'Participou', 'Colocação', 'Medalha', 'Pontos'], ';');
            foreach ($championship->registrations as $item) {
                CsvWriter::row($output, [$item->person->full_name, $item->participation_type, $item->dojo?->name ?? '', $item->status, $item->registration_fee, $item->charge?->status ?? 'sem cobrança', $item->result?->participation_confirmed ? 'sim' : 'não', $item->result?->placement ?? '', $item->result?->medal ?? '', $item->result?->points ?? '0.00'], ';');
            }
            fclose($output);
        }, 'campeonato-'.$championship->id.'-inscricoes.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function catalogs(Association $association): array
    {
        $martialArts = MartialArt::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->orderBy('name')->get();

        return ['association' => $association, 'dojos' => Dojo::query()->where('association_id', $association->id)->where('status', 'active')->orderBy('name')->get(), 'martialArts' => $martialArts, 'people' => Person::query()->whereHas('associations', fn ($query) => $query->whereKey($association->id))->orderBy('full_name')->get(), 'students' => Student::query()->where('association_id', $association->id)->with('person')->orderBy('registration_number')->get(), 'ranks' => Rank::query()->whereIn('martial_art_id', $martialArts->pluck('id'))->with('martialArt')->orderBy('martial_art_id')->orderBy('sort_order')->get(), 'memberships' => FederationMembership::query()->where('association_id', $association->id)->where('status', 'active')->with(['student.person', 'federation', 'martialArt'])->orderByDesc('reference_year')->get()];
    }

    private function scope(Association $association, Championship $championship): void
    {
        abort_unless($championship->association_id === $association->id, 404);
    }
}
