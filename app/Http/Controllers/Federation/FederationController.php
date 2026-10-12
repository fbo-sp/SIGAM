<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DocumentUploadRequest;
use App\Http\Requests\Federation\FederationMembershipRequest;
use App\Http\Requests\Federation\FederationRequest;
use App\Models\Association;
use App\Models\DocumentType;
use App\Models\Federation;
use App\Models\FederationMembership;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\Student;
use App\Services\Documents\DocumentService;
use App\Services\Federation\FederationMembershipService;
use App\Support\Export\CsvWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FederationController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $memberships = $this->membershipQuery($request, $association)->paginate(30)->withQueryString();
        $federations = Federation::query()->where('association_id', $association->id)->orderBy('name')->get();
        $students = Student::query()->where('association_id', $association->id)->whereIn('status', ['active', 'pre_registration'])->with('person')->orderBy('registration_number')->get();
        $martialArts = MartialArt::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->orderBy('name')->get();
        $ranks = Rank::query()->where('status', 'active')->whereIn('martial_art_id', $martialArts->pluck('id'))->with('martialArt')->orderBy('martial_art_id')->orderBy('sort_order')->get();
        $base = FederationMembership::query()->where('association_id', $association->id);
        $summary = ['total' => (clone $base)->count(), 'active' => (clone $base)->where('status', 'active')->where(fn ($query) => $query->whereNull('expiration_date')->orWhereDate('expiration_date', '>=', today()))->count(), 'expiring' => (clone $base)->where('status', 'active')->whereBetween('expiration_date', [today(), today()->addDays(30)])->count(), 'expired' => (clone $base)->whereDate('expiration_date', '<', today())->count()];
        $byFederation = (clone $base)->selectRaw('federation_id, COUNT(*) as total')->groupBy('federation_id')->pluck('total', 'federation_id');

        return view('federation.index', compact('association', 'memberships', 'federations', 'students', 'martialArts', 'ranks', 'summary', 'byFederation'));
    }

    public function storeFederation(FederationRequest $request, Association $association, FederationMembershipService $service): RedirectResponse
    {
        $service->createFederation($association, $request->validated());

        return back()->with('status', 'Federação cadastrada.');
    }

    public function updateFederation(FederationRequest $request, Association $association, Federation $federation, FederationMembershipService $service): RedirectResponse
    {
        $service->updateFederation($association, $federation, $request->validated());

        return back()->with('status', 'Cadastro da federação atualizado. As filiações históricas não foram alteradas.');
    }

    public function storeMembership(FederationMembershipRequest $request, Association $association, FederationMembershipService $service): RedirectResponse
    {
        $membership = $service->createMembership($association, $request->user(), $request->validated());

        return redirect()->route('associations.federation.memberships.show', [$association, $membership])->with('status', 'Filiação registrada. O histórico deste período foi preservado.');
    }

    public function show(Association $association, FederationMembership $membership): View
    {
        $this->ensureScope($association, $membership);
        $membership->load(['student.person', 'federation', 'martialArt', 'rank', 'creator', 'documents.type', 'documents.uploader']);
        $documentTypes = DocumentType::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->orderBy('name')->get();

        return view('federation.show', compact('association', 'membership', 'documentTypes'));
    }

    public function storeDocument(DocumentUploadRequest $request, Association $association, FederationMembership $membership, DocumentService $service): RedirectResponse
    {
        $this->ensureScope($association, $membership);
        $data = [...$request->safe()->except('file'), 'student_id' => $membership->student_id, 'federation_membership_id' => $membership->id];
        $service->upload($association, $request->user(), $data, $request->file('file'));

        return back()->with('status', 'Documento federativo enviado com segurança.');
    }

    public function export(Request $request, Association $association): StreamedResponse
    {
        $memberships = $this->membershipQuery($request, $association)->get();

        return response()->streamDownload(function () use ($memberships): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            CsvWriter::row($output, ['Aluno', 'Federação', 'Modalidade', 'Graduação registrada', 'Número', 'Ano', 'Inscrição', 'Validade', 'Pontos', 'Situação'], ';');
            foreach ($memberships as $item) {
                CsvWriter::row($output, [$item->student->person->full_name, $item->federation->acronym, $item->martialArt->name, $item->rank->display_name, $item->registration_number, $item->reference_year, $item->registration_date->format('d/m/Y'), $item->expiration_date?->format('d/m/Y') ?? '', $item->points, $item->expiryState() === 'expired' ? 'vencida' : $item->status], ';');
            }
            fclose($output);
        }, 'filiacoes-federativas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function membershipQuery(Request $request, Association $association)
    {
        return FederationMembership::query()->where('association_id', $association->id)->with(['student.person', 'federation', 'martialArt', 'rank'])->when($request->filled('federation_id'), fn ($query) => $query->where('federation_id', $request->integer('federation_id')))->when($request->filled('reference_year'), fn ($query) => $query->where('reference_year', $request->integer('reference_year')))->when($request->string('validity')->toString() === 'expiring', fn ($query) => $query->whereBetween('expiration_date', [today(), today()->addDays(30)]))->when($request->string('validity')->toString() === 'expired', fn ($query) => $query->whereDate('expiration_date', '<', today()))->orderByDesc('reference_year')->orderBy('expiration_date')->orderByDesc('id');
    }

    private function ensureScope(Association $association, FederationMembership $membership): void
    {
        abort_unless($membership->association_id === $association->id, 404);
    }
}
