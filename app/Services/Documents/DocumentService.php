<?php

namespace App\Services\Documents;

use App\Models\Association;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FederationMembership;
use App\Models\Person;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function createType(Association $association, array $data): DocumentType
    {
        $type = DocumentType::query()->create([...$data, 'association_id' => $association->id, 'status' => 'active']);
        $this->audit->record('document_type.created', 'create', $type, null, ['name' => $type->name]);

        return $type;
    }

    public function upload(Association $association, User $user, array $data, UploadedFile $file): Document
    {
        $person = isset($data['person_id']) ? Person::query()->find($data['person_id']) : null;
        $student = isset($data['student_id']) ? Student::query()->find($data['student_id']) : null;
        $teacher = isset($data['teacher_profile_id']) ? TeacherProfile::query()->find($data['teacher_profile_id']) : null;
        $guardian = isset($data['student_guardian_id']) ? StudentGuardian::query()->with('student')->find($data['student_guardian_id']) : null;
        $membership = isset($data['federation_membership_id']) ? FederationMembership::query()->find($data['federation_membership_id']) : null;

        if ($person && ! $association->people()->whereKey($person->id)->exists()) {
            throw ValidationException::withMessages(['person_id' => 'A pessoa não pertence a esta associação.']);
        }
        if ($student && $student->association_id !== $association->id) {
            throw ValidationException::withMessages(['student_id' => 'O aluno não pertence a esta associação.']);
        }
        if ($student && $person && $student->person_id !== $person->id) {
            throw ValidationException::withMessages(['person_id' => 'A pessoa deve ser a mesma vinculada ao aluno.']);
        }
        if ($teacher && ! TeacherAssociationMembership::query()->where('association_id', $association->id)
            ->where('teacher_profile_id', $teacher->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['teacher_profile_id' => 'O professor não possui vínculo ativo com esta associação.']);
        }
        if ($guardian && $guardian->student->association_id !== $association->id) {
            throw ValidationException::withMessages(['student_guardian_id' => 'O responsável não pertence a um aluno desta associação.']);
        }
        if ($teacher && $person && $teacher->person_id !== $person->id) {
            throw ValidationException::withMessages(['person_id' => 'A pessoa deve ser a mesma vinculada ao professor.']);
        }
        if ($guardian && $person && $guardian->guardian_person_id !== $person->id) {
            throw ValidationException::withMessages(['person_id' => 'A pessoa deve ser a mesma vinculada ao responsável.']);
        }
        if ($membership && ($membership->association_id !== $association->id || ($student && $membership->student_id !== $student->id))) {
            throw ValidationException::withMessages(['federation_membership_id' => 'A filiação não pertence ao aluno e à associação informados.']);
        }
        $student ??= $membership?->student;
        $person ??= $student?->person ?? $teacher?->person ?? $guardian?->person;

        if (! $person || ($student && $student->person_id !== $person->id)
            || ($teacher && $teacher->person_id !== $person->id)
            || ($guardian && $guardian->guardian_person_id !== $person->id)
            || ($membership && $membership->student?->person_id !== $person->id)) {
            throw ValidationException::withMessages(['person_id' => 'Informe uma pessoa coerente com o aluno e a filiação.']);
        }

        $type = DocumentType::query()->whereKey($data['document_type_id'])->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->first();
        if (! $type) {
            throw ValidationException::withMessages(['document_type_id' => 'Selecione um tipo de documento disponível.']);
        }
        if ($type->requires_expiration && empty($data['expiration_date'])) {
            throw ValidationException::withMessages(['expiration_date' => 'Informe a validade para este tipo de documento.']);
        }

        $storedName = (string) Str::uuid();
        $path = "documents/{$association->id}/{$person->id}/{$storedName}";
        if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
            throw ValidationException::withMessages(['file' => 'Não foi possível armazenar o arquivo. Tente novamente.']);
        }

        try {
            return DB::transaction(function () use ($association, $user, $data, $file, $person, $student, $storedName, $path): Document {
                $document = Document::query()->create([
                    ...$data,
                    'association_id' => $association->id,
                    'person_id' => $person->id,
                    'student_id' => $student?->id,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'stored_name' => $storedName,
                    'storage_path' => $path,
                    'mime_type' => (string) $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'status' => 'active',
                    'uploaded_by' => $user->id,
                ]);
                $this->audit->record('document.uploaded', 'create', $document, null, ['document_type_id' => $document->document_type_id]);

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $document->update(['status' => 'inactive']);
            $document->delete();
            $this->audit->record('document.deleted', 'delete', $document, ['status' => 'active'], ['status' => 'inactive']);
        });
    }
}
