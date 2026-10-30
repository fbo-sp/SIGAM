<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\GuardianEndRequest;
use App\Http\Requests\Sports\GuardianRequest;
use App\Http\Requests\Sports\GuardianUpdateRequest;
use App\Models\Association;
use App\Models\Dojo;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\Sports\GuardianService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Responsáveis do aluno pela associação (`/associacoes/{association}/alunos/{student}/responsaveis`) e pelo dojo
 * (`/operacao/dojos/{dojo}/alunos/{student}/responsaveis`, gestor do dojo e professor das turmas do aluno).
 */
class GuardianController extends Controller
{
    public function __construct(private readonly GuardianService $guardians) {}

    public function dojo(Request $request, Dojo $dojo, Student $student): View
    {
        $this->authorizeDojo($request, $dojo, $student);
        $student->load(['person', 'enrollments' => fn ($query) => $query->where('status', 'active')->coveringDate(today()->toDateString())->with('classGroup:id,name')]);

        return view('operation.student-guardians', ['dojo' => $dojo, 'student' => $student, ...$this->guardians->viewData($request->user(), $student, $dojo)]);
    }

    public function store(GuardianRequest $request, Association $association, Student $student): RedirectResponse
    {
        $this->authorizeAssociation($request, $association, $student);

        return $this->added($this->guardians->add($request->user(), $association, $student, $request->validated()));
    }

    public function storeDojo(GuardianRequest $request, Dojo $dojo, Student $student): RedirectResponse
    {
        $this->authorizeDojo($request, $dojo, $student);

        return $this->added($this->guardians->add($request->user(), $dojo->association, $student, $request->validated(), $dojo));
    }

    public function update(GuardianUpdateRequest $request, Association $association, Student $student, StudentGuardian $guardian): RedirectResponse
    {
        $this->authorizeAssociation($request, $association, $student);
        $this->guardians->update($association, $student, $guardian, $request->validated());

        return back()->with('status', 'Responsável atualizado.');
    }

    public function updateDojo(GuardianUpdateRequest $request, Dojo $dojo, Student $student, StudentGuardian $guardian): RedirectResponse
    {
        $this->authorizeDojo($request, $dojo, $student);
        $this->guardians->update($dojo->association, $student, $guardian, $request->validated(), $dojo);

        return back()->with('status', 'Responsável atualizado.');
    }

    public function end(GuardianEndRequest $request, Association $association, Student $student, StudentGuardian $guardian): RedirectResponse
    {
        $this->authorizeAssociation($request, $association, $student);
        $this->guardians->end($association, $student, $guardian, $request->validated());

        return back()->with('status', 'Vínculo encerrado. O histórico continua guardado.');
    }

    public function endDojo(GuardianEndRequest $request, Dojo $dojo, Student $student, StudentGuardian $guardian): RedirectResponse
    {
        $this->authorizeDojo($request, $dojo, $student);
        $this->guardians->end($dojo->association, $student, $guardian, $request->validated(), $dojo);

        return back()->with('status', 'Vínculo encerrado. O histórico continua guardado.');
    }

    public function portal(Request $request, Association $association, Student $student, StudentGuardian $guardian): RedirectResponse
    {
        $this->authorizeAssociation($request, $association, $student);

        return back()->with('status', $this->guardians->releasePortal($request->user(), $association, $student, $guardian, $this->email($request)));
    }

    public function portalDojo(Request $request, Dojo $dojo, Student $student, StudentGuardian $guardian): RedirectResponse
    {
        $this->authorizeDojo($request, $dojo, $student);

        return back()->with('status', $this->guardians->releasePortal($request->user(), $dojo->association, $student, $guardian, $this->email($request)));
    }

    public function requires(Request $request, Association $association, Student $student): RedirectResponse
    {
        $this->authorizeAssociation($request, $association, $student);
        $this->guardians->setRequiresGuardian($association, $student, $request->boolean('requires_guardian'));

        return back()->with('status', $this->requiresMessage($request));
    }

    public function requiresDojo(Request $request, Dojo $dojo, Student $student): RedirectResponse
    {
        $this->authorizeDojo($request, $dojo, $student);
        $this->guardians->setRequiresGuardian($dojo->association, $student, $request->boolean('requires_guardian'), $dojo);

        return back()->with('status', $this->requiresMessage($request));
    }

    private function added(array $result): RedirectResponse
    {
        $message = $result['reused'] ? 'Responsável vinculado usando o cadastro que já existia.' : 'Responsável cadastrado e vinculado.';

        return back()->with('status', trim($message.' '.($result['portal'] ?? '')));
    }

    private function email(Request $request): ?string
    {
        return $request->validate(['email' => ['nullable', 'email:rfc', 'max:255']])['email'] ?? null;
    }

    private function requiresMessage(Request $request): string
    {
        return $request->boolean('requires_guardian')
            ? 'Marcado: o aluno precisa de responsável mesmo sendo maior de idade.'
            : 'Desmarcado: só menores de idade precisam de responsável.';
    }

    private function authorizeAssociation(Request $request, Association $association, Student $student): void
    {
        abort_unless($student->association_id === $association->id, 404);
        abort_unless($this->guardians->canManage($request->user(), $student), 403);
    }

    private function authorizeDojo(Request $request, Dojo $dojo, Student $student): void
    {
        abort_unless($student->association_id === $dojo->association_id, 404);
        abort_unless($this->guardians->canManage($request->user(), $student, $dojo), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}
