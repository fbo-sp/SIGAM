<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\DojoStudentPersonRequest;
use App\Models\Dojo;
use App\Models\Student;
use App\Services\Access\OperationalScope;
use App\Services\People\PersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** O gestor do dojo corrige os dados pessoais dos alunos com vínculo vigente na unidade. */
class DojoStudentPersonController extends Controller
{
    public function __construct(private readonly OperationalScope $scope) {}

    public function edit(Request $request, Dojo $dojo, Student $student): View
    {
        $this->authorizeStudent($request, $dojo, $student);

        return view('operation.student-edit', ['dojo' => $dojo, 'student' => $student, 'person' => $student->person]);
    }

    public function update(DojoStudentPersonRequest $request, Dojo $dojo, Student $student, PersonService $service): RedirectResponse
    {
        $this->authorizeStudent($request, $dojo, $student);
        $service->update($dojo->association, $student->person, $request->validated());

        return redirect()->route('operation.students', $dojo)->with('status', 'Dados do aluno atualizados.');
    }

    private function authorizeStudent(Request $request, Dojo $dojo, Student $student): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo) && $this->scope->canDojo($request->user(), $dojo, 'student.manage'), 403);
        abort_unless($this->scope->students($request->user(), $dojo)->whereKey($student->id)->exists(), 404);
        // A mesma pessoa também professora é mantida pela associação.
        abort_if($student->person->teacherProfile()->exists(), 403);
        view()->share('currentAssociation', $dojo->association);
    }
}
