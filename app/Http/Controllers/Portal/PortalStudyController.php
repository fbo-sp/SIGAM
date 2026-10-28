<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TechniqueItem;
use App\Services\Portal\PortalAccessService;
use App\Services\Progression\AssessmentService;
use App\Services\Progression\StudentProgressService;
use App\Services\Progression\TechniqueStudyService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Estudar no portal: o próprio aluno ou o responsável, só dos dependentes, na modalidade ativa escolhida. */
class PortalStudyController extends Controller
{
    public function __construct(private readonly PortalAccessService $access, private readonly StudentProgressService $progress, private readonly TechniqueStudyService $study) {}

    public function index(Request $request, Student $student): View
    {
        [$student, $martialArt, $options] = $this->context($request, $student);
        $data = $this->study->catalog($student->association, $student, $martialArt);
        $counts = collect(TechniqueStudyService::FILTERS)->map(fn ($label, $key) => $this->study->filter($data['rows'], $key, null)->count());
        $filter = $request->query('filtro');
        $filter = isset(TechniqueStudyService::FILTERS[$filter]) ? $filter : ($counts['melhorar'] > 0 ? 'melhorar' : ($counts['proxima'] > 0 ? 'proxima' : 'todas'));
        $term = mb_substr(trim((string) $request->query('q')), 0, 80);
        $rows = $this->study->filter($data['rows'], $term === '' ? $filter : 'todas', $term);

        return view('portal.study.index', [
            'student' => $student, 'martialArt' => $martialArt, 'options' => $options, 'snapshot' => $data['snapshot'],
            'filter' => $term === '' ? $filter : null, 'counts' => $counts, 'term' => $term, 'groups' => $rows->groupBy('group'),
        ]);
    }

    public function show(Request $request, Student $student, TechniqueItem $item): View
    {
        [$student, $martialArt] = $this->context($request, $student, $item);
        $data = $this->study->catalog($student->association, $student, $martialArt);
        $entry = $data['rows']->first(fn (array $row) => $row['item']->id === $item->id);
        abort_if($entry === null, 404);
        $related = $data['rows']->filter(fn (array $row) => $row['item']->parent_id === $item->parent_id && $row['item']->id !== $item->id)->take(4)->values();
        $history = app(AssessmentService::class)->timeline($student, $martialArt, [$item->id]);

        return view('portal.study.show', [
            'student' => $student, 'martialArt' => $martialArt, 'snapshot' => $data['snapshot'], 'entry' => $entry, 'item' => $item,
            'related' => $related, 'assessments' => $history->count(), 'levels' => AssessmentLevel::labels(),
            'studiedToday' => $item->studyLogs()->where('student_id', $student->id)->whereDate('studied_on', today())->exists(),
        ]);
    }

    public function studied(Request $request, Student $student, TechniqueItem $item): RedirectResponse
    {
        [$student] = $this->context($request, $student, $item);
        $created = $this->study->markStudied($request->user(), $student, $item);

        return back()->with('status', $created ? 'Estudo registrado. O professor vê na sua ficha de acompanhamento.' : 'O estudo de hoje já estava registrado.');
    }

    /** @return array{Student, MartialArt, Collection} */
    private function context(Request $request, Student $student, ?TechniqueItem $item = null): array
    {
        $student = $this->access->resolveStudent($request->user(), $student);
        $options = $this->progress->martialArts($student);
        $wanted = $item?->martial_art_id ?? $request->integer('modalidade');
        $link = $options->firstWhere('martial_art_id', $wanted) ?? ($item ? null : $options->first());
        abort_if($link === null, 404);
        abort_if($item && ($item->association_id !== $student->association_id || $item->kind !== 'item' || $item->status !== 'active'), 404);
        $student->load('person');

        return [$student, $link->martialArt, $options];
    }
}
