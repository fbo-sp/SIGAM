<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\DirectStudentRegistrationRequest;
use App\Http\Requests\Sports\DirectTeacherRegistrationRequest;
use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\DirectRegistrationRequest;
use App\Models\DocumentType;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Person;
use App\Models\Student;
use App\Services\Access\OperationalScope;
use App\Services\Documents\DocumentService;
use App\Services\Sports\DirectRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DirectRegistrationController extends Controller
{
    public function __construct(private readonly OperationalScope $scope) {}

    public function createAssociationStudent(Request $request, Association $association): View
    {
        return view('sports.students.direct', $this->studentFormData(
            $association,
            null,
            route('associations.students.direct.store', $association),
            route('associations.students.index', $association),
            $request->user()->hasPermission('document.manage', $association->id),
            true,
            $request->user()->hasPermission('enrollment.manage', $association->id),
        ));
    }

    public function storeAssociationStudent(
        DirectStudentRegistrationRequest $request,
        Association $association,
        DirectRegistrationService $registration,
        DocumentService $documents,
    ): RedirectResponse {
        $dojo = Dojo::query()->where('association_id', $association->id)->where('status', 'active')->findOrFail($request->integer('dojo_id'));
        $canUpload = $request->user()->hasPermission('document.manage', $association->id);
        abort_if($request->hasFile('document_file') && ! $canUpload, 403);
        abort_if($request->filled('class_group_ids') && ! $request->user()->hasPermission('enrollment.manage', $association->id), 403);
        $this->validateDocumentType($request, $association);
        $result = $registration->registerStudent($association, $dojo, $request->user(), $request->validated());
        $this->storeStudentDocument($request, $association, $result['model'], $result['request'], $documents, $canUpload);

        return redirect()->route('associations.students.show', [$association, $result['model']])
            ->with('status', $this->studentMessage($request, $result, 'Cadastro do aluno salvo.'));
    }

    public function createDojoStudent(Request $request, Dojo $dojo): View
    {
        $this->authorizeDojoStudent($request, $dojo);
        view()->share('currentAssociation', $dojo->association);

        return view('sports.students.direct', $this->studentFormData(
            $dojo->association,
            $dojo,
            route('operation.students.direct.store', $dojo),
            route('operation.students', $dojo),
            $this->scope->canDojo($request->user(), $dojo, 'student.document.manage'),
            false,
            $this->scope->canDojo($request->user(), $dojo, 'enrollment.manage'),
        ));
    }

    public function storeDojoStudent(
        DirectStudentRegistrationRequest $request,
        Dojo $dojo,
        DirectRegistrationService $registration,
        DocumentService $documents,
    ): RedirectResponse {
        $this->authorizeDojoStudent($request, $dojo);
        abort_unless($request->integer('dojo_id') === $dojo->id, 404);
        $canUpload = $this->scope->canDojo($request->user(), $dojo, 'student.document.manage');
        abort_if($request->hasFile('document_file') && ! $canUpload, 403);
        abort_if($request->filled('class_group_ids') && ! $this->scope->canDojo($request->user(), $dojo, 'enrollment.manage'), 403);
        $this->validateDocumentType($request, $dojo->association);
        $result = $registration->registerStudent($dojo->association, $dojo, $request->user(), $request->validated());
        $this->storeStudentDocument($request, $dojo->association, $result['model'], $result['request'], $documents, $canUpload);

        return redirect()->route('operation.students', $dojo)
            ->with('status', $this->studentMessage($request, $result, 'Aluno salvo neste dojo.'));
    }

    public function createTeacher(Request $request, Association $association): View
    {
        return view('sports.teachers.direct', [
            'association' => $association,
            'people' => $this->people($association),
            'martialArts' => $this->martialArts($association),
            'documentTypes' => $this->documentTypes($association),
            'canUploadDocuments' => $request->user()->hasPermission('document.manage', $association->id),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeTeacher(
        DirectTeacherRegistrationRequest $request,
        Association $association,
        DirectRegistrationService $registration,
        DocumentService $documents,
    ): RedirectResponse {
        abort_if($request->hasFile('document_file') && ! $request->user()->hasPermission('document.manage', $association->id), 403);
        $this->validateDocumentType($request, $association);
        $result = $registration->registerTeacher($association, $request->user(), $request->validated());
        if ($request->hasFile('document_file')) {
            abort_unless($request->user()->hasPermission('document.manage', $association->id), 403);
            $this->storeDocumentOnce($result['request'], fn () => $documents->upload($association, $request->user(), [
                'person_id' => $result['model']->person_id,
                'teacher_profile_id' => $result['model']->id,
                'document_type_id' => $request->integer('document_type_id'),
                'expiration_date' => $request->input('document_expiration_date'),
            ], $request->file('document_file')));
        }

        return redirect()->route('associations.teachers.index', $association)
            ->with('status', $result['replayed'] ? 'Cadastro já processado anteriormente; nenhum registro foi duplicado.' : ($request->boolean('send_invitation') ? 'Professor cadastrado e convite preparado para envio.' : 'Professor cadastrado sem convite de acesso.'));
    }

    private function studentMessage(Request $request, array $result, string $saved): string
    {
        if ($result['replayed']) {
            return 'Cadastro já processado anteriormente; nenhum registro foi duplicado.';
        }
        if ($request->input('finish_action') === 'activate' && $result['model']->enrollments()->where('status', 'active')->doesntExist()) {
            return $saved.' Atenção: o aluno ainda não está em nenhuma turma. Matricule-o para que apareça na chamada.';
        }
        if ($request->input('finish_action') !== 'activate' && $request->filled('class_group_ids')) {
            return $saved.' As turmas não foram gravadas no pré-cadastro: matricule o aluno depois de ativá-lo.';
        }

        return $saved;
    }

    private function studentFormData(Association $association, ?Dojo $dojo, string $postRoute, string $backRoute, bool $canUploadDocuments, bool $allowExistingSelection, bool $canEnroll): array
    {
        $dojos = $dojo ? collect([$dojo]) : $association->dojos()->where('status', 'active')->orderBy('name')->get();

        return [
            'association' => $association,
            'fixedDojo' => $dojo,
            'dojos' => $dojos,
            'canEnroll' => $canEnroll,
            'classGroups' => $canEnroll ? ClassGroup::query()->where('association_id', $association->id)->whereIn('dojo_id', $dojos->pluck('id'))
                ->where('status', 'active')->with('martialArt:id,name')->orderBy('name')->get()->groupBy('dojo_id') : collect(),
            'people' => $allowExistingSelection ? $this->people($association) : collect(),
            'martialArts' => $this->martialArts($association),
            'documentTypes' => $this->documentTypes($association),
            'canUploadDocuments' => $canUploadDocuments,
            'idempotencyKey' => (string) Str::uuid(),
            'postRoute' => $postRoute,
            'backRoute' => $backRoute,
        ];
    }

    private function people(Association $association)
    {
        return Person::query()->where('status', 'active')->whereHas('associations', fn ($query) => $query->whereKey($association->id)
            ->where('association_person_roles.status', 'active'))->orderBy('full_name')->limit(100)->get();
    }

    private function martialArts(Association $association)
    {
        return MartialArt::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')
            ->orWhere('association_id', $association->id))->with(['ranks' => fn ($query) => $query->where('status', 'active')->orderBy('sort_order')])
            ->orderBy('name')->get();
    }

    private function documentTypes(Association $association)
    {
        return DocumentType::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')
            ->orWhere('association_id', $association->id))->orderBy('name')->get();
    }

    private function authorizeDojoStudent(Request $request, Dojo $dojo): void
    {
        abort_unless($this->scope->canEnter($request->user(), $dojo)
            && $this->scope->canDojo($request->user(), $dojo, 'student.manage'), 403);
    }

    private function storeStudentDocument(DirectStudentRegistrationRequest $request, Association $association, Student $student, DirectRegistrationRequest $submission, DocumentService $documents, bool $authorized): void
    {
        if (! $request->hasFile('document_file')) {
            return;
        }
        abort_unless($authorized, 403);
        $owner = $request->input('document_owner', 'student');
        $guardian = $owner === 'guardian' ? $student->guardians()->with('person')->latest('id')->first() : null;
        if ($owner === 'guardian' && $guardian === null) {
            throw ValidationException::withMessages([
                'document_owner' => 'Cadastre o responsável antes de vincular um documento a ele.',
            ]);
        }
        $this->storeDocumentOnce($submission, fn () => $documents->upload($association, $request->user(), [
            'person_id' => $guardian?->guardian_person_id ?? $student->person_id,
            'student_id' => $guardian === null ? $student->id : null,
            'student_guardian_id' => $guardian?->id,
            'document_type_id' => $request->integer('document_type_id'),
            'expiration_date' => $request->input('document_expiration_date'),
        ], $request->file('document_file')));
    }

    private function storeDocumentOnce(DirectRegistrationRequest $submission, callable $upload): void
    {
        $submission->refresh();
        if ($submission->document_id !== null) {
            return;
        }
        $document = $upload();
        $submission->update(['document_id' => $document->id]);
    }

    private function validateDocumentType(Request $request, Association $association): void
    {
        if (! $request->hasFile('document_file')) {
            return;
        }
        $type = DocumentType::query()->whereKey($request->integer('document_type_id'))->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->first();
        if ($type === null) {
            throw ValidationException::withMessages(['document_type_id' => 'Selecione um tipo de documento disponível nesta associação.']);
        }
        if ($type->requires_expiration && ! $request->filled('document_expiration_date')) {
            throw ValidationException::withMessages(['document_expiration_date' => 'Informe a validade para este tipo de documento.']);
        }
    }
}
