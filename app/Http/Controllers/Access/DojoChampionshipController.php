<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\Dojo;
use App\Services\Access\OperationalScope;
use App\Services\Championship\ChampionshipRegistrationService;
use Illuminate\Http\Request;

class DojoChampionshipController extends Controller
{
    public function __construct(private readonly OperationalScope $scope) {}

    public function index(Request $request, Dojo $dojo)
    {
        $this->authorizeContext($request, $dojo, 'championship.view');
        $championships = Championship::query()->where('association_id', $dojo->association_id)
            ->whereIn('status', ['open', 'closed', 'completed'])
            ->with('martialArt:id,name')
            ->withCount(['registrations as local_registrations_count' => fn ($query) => $query->where('dojo_id', $dojo->id)])
            ->withExists(['dojoParticipations as local_participation' => fn ($query) => $query->where('dojo_id', $dojo->id)->where('status', 'active')])
            ->orderBy('start_date')->paginate(25);

        return view('operation.championships', compact('dojo', 'championships'));
    }

    public function show(Request $request, Dojo $dojo, Championship $championship)
    {
        $this->authorizeContext($request, $dojo, 'championship.view');
        abort_unless($championship->association_id === $dojo->association_id, 404);
        $studentIds = $this->scope->students($request->user(), $dojo)->select('students.id');
        $students = $this->scope->students($request->user(), $dojo)
            ->whereHas('martialArts', fn ($query) => $query->where('martial_art_id', $championship->martial_art_id)->where('status', 'active'))
            ->with('person:id,full_name,birth_date')->orderBy('registration_number')->get();
        $registrations = ChampionshipRegistration::query()->where('championship_id', $championship->id)->where('dojo_id', $dojo->id)
            ->whereIn('student_id', $studentIds)->with(['student.person:id,full_name', 'rank:id,display_name'])->latest()->paginate(25);
        $canRegister = $this->scope->canDojo($request->user(), $dojo, 'championship.register') && $championship->acceptsRegistrations();

        return view('operation.championship', compact('dojo', 'championship', 'students', 'registrations', 'canRegister'));
    }

    public function register(Request $request, Dojo $dojo, Championship $championship, ChampionshipRegistrationService $service)
    {
        $this->authorizeContext($request, $dojo, 'championship.register');
        abort_unless($championship->association_id === $dojo->association_id, 404);
        $data = $request->validate([
            'student_id' => ['required', 'integer'], 'category' => ['required', 'string', 'max:120'],
            'weight' => ['required', 'decimal:0,2', 'gt:0'],
            'team_name' => ['nullable', 'string', 'max:180'], 'federation_membership_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $service->registerForDojo($dojo->association, $dojo, $championship, $request->user(), $data);

        return back()->with('status', 'Inscrição enviada para análise da associação.');
    }

    private function authorizeContext(Request $request, Dojo $dojo, string $permission): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo), 403);
        abort_unless($this->scope->canDojo($request->user(), $dojo, $permission), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}
