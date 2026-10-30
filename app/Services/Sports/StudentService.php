<?php

namespace App\Services\Sports;

use App\Models\Association;
use App\Models\Student;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function activate(Association $association, Student $student): Student
    {
        return DB::transaction(function () use ($association, $student): Student {
            $student->load('person');
            if ($student->status !== 'pre_registration') {
                throw ValidationException::withMessages(['status' => 'Somente um pré-cadastro pode ser ativado.']);
            }
            // Sem data de nascimento o aluno pode ser ativado e fica com pendência de cadastro (decisão de Fabio em 29/10/2026).
            // Vale para o menor e para o aluno marcado como "precisa de responsável" (decisão de Fabio em 30/10/2026).
            if ($association->requires_minor_guardian && $student->needsGuardian() && ! $student->guardians()->current()->where('is_legal_guardian', true)->exists()) {
                throw ValidationException::withMessages(['guardian' => 'Cadastre ao menos um responsável legal vigente antes de ativar este aluno.']);
            }
            $student->update(['status' => 'active']);
            $this->audit->record('student.activated', 'updated', $student, ['status' => 'pre_registration'], ['status' => 'active']);

            return $student;
        });
    }

    private function assertAssociationActive(Association $association): void
    {
        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association' => 'Ative a associação antes de cadastrar alunos.']);
        }
    }
}
