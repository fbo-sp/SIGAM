<?php

namespace App\Services\Sports;

use App\Models\Association;
use App\Models\Person;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function addGuardian(Association $association, Student $student, array $data): StudentGuardian
    {
        return DB::transaction(function () use ($association, $student, $data): StudentGuardian {
            $guardian = $this->personInAssociation($association, (int) $data['guardian_person_id']);
            if ($guardian->id === $student->person_id) {
                throw ValidationException::withMessages(['guardian_person_id' => 'O aluno não pode ser o próprio responsável.']);
            }
            if ($data['is_primary'] ?? false) {
                $student->guardians()->whereNull('end_date')->update(['is_primary' => false]);
            }
            $link = $student->guardians()->create($data);
            $this->audit->record('student.guardian_added', 'created', $student, null, ['guardian_link_id' => $link->id], ['association_id' => $association->id]);

            return $link;
        });
    }

    public function activate(Association $association, Student $student): Student
    {
        return DB::transaction(function () use ($association, $student): Student {
            $student->load('person');
            if ($student->status !== 'pre_registration') {
                throw ValidationException::withMessages(['status' => 'Somente um pré-cadastro pode ser ativado.']);
            }
            if ($student->person->birth_date === null) {
                throw ValidationException::withMessages(['birth_date' => 'Informe a data de nascimento da pessoa antes de ativar o aluno.']);
            }
            if ($association->requires_minor_guardian && $student->person->birth_date->age < 18 && ! $student->guardians()->where('is_legal_guardian', true)->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', today()))->exists()) {
                throw ValidationException::withMessages(['guardian' => 'Cadastre ao menos um responsável legal vigente antes de ativar um aluno menor.']);
            }
            $student->update(['status' => 'active']);
            $this->audit->record('student.activated', 'updated', $student, ['status' => 'pre_registration'], ['status' => 'active']);

            return $student;
        });
    }

    private function personInAssociation(Association $association, int $personId): Person
    {
        return Person::query()->whereKey($personId)->where('status', 'active')->whereHas('associations', fn ($query) => $query->whereKey($association->id))->firstOrFail();
    }

    private function assertAssociationActive(Association $association): void
    {
        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association' => 'Ative a associação antes de cadastrar alunos.']);
        }
    }
}
