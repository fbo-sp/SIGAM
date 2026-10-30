<?php

namespace App\Services\Sports;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\Dojo;
use App\Models\Person;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\Access\UserAccountService;
use App\Services\AuditService;
use App\Services\Classes\TeacherWorkspaceService;
use App\Services\Import\StudentDuplicateFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Responsáveis dos alunos (decisões de Fabio em 30/10/2026): o gestor da associação cuida de todos os alunos, o gestor
 * do dojo dos alunos com vínculo vigente na unidade e o professor só dos alunos das turmas dele (principal ou
 * auxiliar). Vale para aluno ativo e em pré-cadastro. Encerrar nunca apaga o vínculo: grava a data de fim.
 */
class GuardianService
{
    public const RELATIONSHIPS = [
        'mother' => 'Mãe', 'father' => 'Pai', 'grandparent' => 'Avô/avó', 'tutor' => 'Tutor', 'legal_guardian' => 'Responsável legal',
        'financial_guardian' => 'Responsável financeiro', 'emergency_contact' => 'Contato de emergência', 'other' => 'Outro',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly OperationalScope $scope,
        private readonly TeacherWorkspaceService $teachers,
        private readonly UserAccountService $accounts,
    ) {}

    /** Sem dojo: contexto da associação (`student.manage`). Com dojo: gestor do dojo ou professor das turmas do aluno. */
    public function canManage(User $user, Student $student, ?Dojo $dojo = null): bool
    {
        if ($dojo === null) {
            return ($user->association_id === null || $user->association_id === $student->association_id)
                && $user->hasPermission('student.manage', $student->association_id);
        }
        if ($dojo->association_id !== $student->association_id || ! $this->scope->canEnter($user, $dojo)) {
            return false;
        }
        if ($this->scope->canDojo($user, $dojo, 'student.manage')) {
            return $this->scope->students($user, $dojo)->whereKey($student->id)->exists();
        }

        return $user->association_id === $dojo->association_id && $this->teachers->teacherId($user) !== null
            && ClassEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->coveringDate(today()->toDateString())
                ->whereIn('class_group_id', $this->teachers->groups($user)->where('dojo_id', $dojo->id)->select('id'))->exists();
    }

    /** @return Collection<int, StudentGuardian> vínculos vigentes, o principal primeiro */
    public function current(Student $student): Collection
    {
        return $student->guardians()->current()->with('person.user')->orderByDesc('is_primary')->orderByDesc('is_legal_guardian')->orderBy('start_date')->get();
    }

    /**
     * Pessoas que podem ser escolhidas como "já cadastrada". Associação: as pessoas ativas dela. Dojo: os responsáveis
     * vigentes dos outros alunos que a conta já vê (irmãos), sem abrir o cadastro de pessoas da associação.
     *
     * @return Collection<int, Person>
     */
    public function candidates(User $user, Student $student, ?Dojo $dojo = null): Collection
    {
        return $this->candidateQuery($user, $student, $dojo)->whereNotIn('people.id', $student->guardians()->current()->select('guardian_person_id'))->orderBy('full_name')->limit(300)->get(['people.id', 'people.full_name', 'people.mobile']);
    }

    /**
     * Dados do bloco Responsáveis (resources/views/sports/students/partials/guardians.blade.php).
     *
     * @return array<string, mixed>
     */
    public function viewData(User $user, Student $student, ?Dojo $dojo = null): array
    {
        $canManage = $this->canManage($user, $student, $dojo);

        return [
            'guardianLinks' => $this->current($student),
            'guardianCandidates' => $canManage ? $this->candidates($user, $student, $dojo) : collect(),
            'guardianRoutes' => $dojo
                ? ['prefix' => 'operation.students.guardians.', 'base' => [$dojo, $student]]
                : ['prefix' => 'associations.students.guardians.', 'base' => [$student->association_id, $student]],
            'canManageGuardians' => $canManage,
        ];
    }

    /** @return array{link: StudentGuardian, reused: bool, portal: ?string} */
    public function add(User $actor, Association $association, Student $student, array $data, ?Dojo $dojo = null): array
    {
        [$link, $reused] = DB::transaction(function () use ($actor, $association, $student, $data, $dojo): array {
            $locked = $this->lockStudent($association, $student);
            [$person, $reused] = ($data['mode'] ?? 'existing') === 'new'
                ? $this->resolveNew($association, $data)
                : [$this->candidateQuery($actor, $locked, $dojo)->lockForUpdate()->findOrFail($data['guardian_person_id']), true];
            if ($person->id === $locked->person_id) {
                throw ValidationException::withMessages(['guardian_person_id' => 'O aluno não pode ser o próprio responsável.']);
            }
            if ($locked->guardians()->current()->where('guardian_person_id', $person->id)->exists()) {
                throw ValidationException::withMessages(['guardian_person_id' => "{$person->full_name} já é responsável por este aluno."]);
            }
            if ($data['is_primary'] ?? false) {
                $locked->guardians()->current()->update(['is_primary' => false]);
            }
            $link = $locked->guardians()->create([
                'guardian_person_id' => $person->id, 'relationship_type' => $data['relationship_type'], 'start_date' => $data['start_date'],
                ...$this->flags($data),
            ]);
            $this->audit->record('student.guardian_added', 'created', $locked, null, [
                'guardian_link_id' => $link->id, 'guardian_person_id' => $person->id, 'relationship_type' => $link->relationship_type, ...$this->flags($data),
            ], ['association_id' => $association->id, 'dojo_id' => $dojo?->id, 'reused_person' => $reused]);

            return [$link, $reused];
        }, 3);

        $portal = ($data['release_portal'] ?? false) ? $this->releasePortal($actor, $association, $student, $link, $data['email'] ?? null) : null;

        return ['link' => $link, 'reused' => $reused, 'portal' => $portal];
    }

    public function update(Association $association, Student $student, StudentGuardian $link, array $data, ?Dojo $dojo = null): StudentGuardian
    {
        return DB::transaction(function () use ($association, $student, $link, $data, $dojo): StudentGuardian {
            $locked = $this->lockLink($association, $student, $link);
            $old = $locked->only(['relationship_type', 'is_legal_guardian', 'is_financial_guardian', 'is_primary', 'can_receive_notifications']);
            if (($data['is_primary'] ?? false) && ! $locked->is_primary) {
                StudentGuardian::query()->where('student_id', $locked->student_id)->whereKeyNot($locked->id)->current()->update(['is_primary' => false]);
            }
            $locked->update(['relationship_type' => $data['relationship_type'], ...$this->flags($data)]);
            $new = $locked->only(array_keys($old));
            if ($old != $new) {
                $this->audit->record('student.guardian_updated', 'updated', $locked->student, $old, $new, [
                    'association_id' => $association->id, 'dojo_id' => $dojo?->id, 'guardian_link_id' => $locked->id,
                ]);
            }

            return $locked;
        }, 3);
    }

    public function end(Association $association, Student $student, StudentGuardian $link, array $data, ?Dojo $dojo = null): StudentGuardian
    {
        return DB::transaction(function () use ($association, $student, $link, $data, $dojo): StudentGuardian {
            $locked = $this->lockLink($association, $student, $link);
            $end = $data['end_date'];
            if ($end < $locked->start_date->toDateString()) {
                throw ValidationException::withMessages(['end_date' => 'O fim não pode ser antes do início do vínculo ('.$locked->start_date->format('d/m/Y').').']);
            }
            $locked->update(['end_date' => $end, 'is_primary' => false]);
            $this->audit->record('student.guardian_ended', 'updated', $locked->student, ['end_date' => null], ['end_date' => $end], [
                'association_id' => $association->id, 'dojo_id' => $dojo?->id, 'guardian_link_id' => $locked->id,
                'guardian_person_id' => $locked->guardian_person_id, 'reason' => $data['reason'] ?? null,
            ]);

            return $locked;
        }, 3);
    }

    public function setRequiresGuardian(Association $association, Student $student, bool $required, ?Dojo $dojo = null): Student
    {
        return DB::transaction(function () use ($association, $student, $required, $dojo): Student {
            $locked = $this->lockStudent($association, $student);
            if ($locked->requires_guardian !== $required) {
                $locked->update(['requires_guardian' => $required]);
                $this->audit->record('student.requires_guardian_changed', 'updated', $locked, ['requires_guardian' => ! $required], ['requires_guardian' => $required], [
                    'association_id' => $association->id, 'dojo_id' => $dojo?->id,
                ]);
            }

            return $locked;
        }, 3);
    }

    /**
     * Envia o convite do portal ao responsável. Quem já tem conta na associação entra no portal sozinho (decisão de
     * Fabio em 30/10/2026), então nada é criado. Sem e-mail no cadastro, usa o informado e grava na pessoa.
     */
    public function releasePortal(User $actor, Association $association, Student $student, StudentGuardian $link, ?string $email = null): string
    {
        $link = $this->lockLink($association, $student, $link, false);
        $person = $link->person()->with('user')->firstOrFail();
        if ($person->user) {
            return $person->user->association_id === $association->id
                ? "{$person->full_name} já tem conta e vê o aluno no portal."
                : "{$person->full_name} já tem conta em outra associação; fale com o gestor da associação.";
        }
        $email = mb_strtolower(trim((string) ($email ?: $person->email)));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => "Informe um e-mail válido de {$person->full_name} para enviar o convite."]);
        }
        if (blank($person->email)) {
            $person->update(['email' => $email]);
        }
        $result = $this->accounts->invitePortal($actor, $association, $person, $email);

        return $result['sent'] ? "Convite do portal enviado para {$email}." : 'A conta foi criada, mas o e-mail do convite não saiu. Reenvie pela tela de usuários.';
    }

    /** @return array{0: Person, 1: bool} pessoa e se o cadastro já existia */
    private function resolveNew(Association $association, array $data): array
    {
        $cpf = $data['cpf'] ?? null;
        if ($cpf !== null) {
            $existing = Person::query()->withTrashed()->where('cpf', $cpf)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->trashed() || $existing->status !== 'active' || ! $existing->associations()->whereKey($association->id)->wherePivot('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['cpf' => 'Este CPF pertence a um cadastro que não está ativo nesta associação. Peça ao gestor da associação para revisar.']);
                }

                return [$this->fillEmpty($existing, $data), true];
            }
        }
        $mobile = $data['mobile'] ?? null;
        $name = StudentDuplicateFinder::name($data['full_name']);
        if ($mobile !== null) {
            $same = $this->associationPeople($association)->where('mobile', $mobile)->lockForUpdate()->get()
                ->first(fn (Person $person) => StudentDuplicateFinder::name($person->full_name) === $name && ($cpf === null || blank($person->cpf)));
            if ($same) {
                return [$this->fillEmpty($same, $data), true];
            }
        }
        $person = Person::query()->create([
            'full_name' => trim($data['full_name']), 'cpf' => $cpf, 'mobile' => $mobile, 'email' => $data['email'] ?? null,
            'country' => 'BR', 'status' => 'active',
        ]);
        $association->people()->attach($person->id, ['role_type' => 'responsável', 'start_date' => today()->toDateString(), 'status' => 'active']);

        return [$person, false];
    }

    /** Cadastro reaproveitado: só completa celular, e-mail e CPF vazios; nunca troca o que já existe. */
    private function fillEmpty(Person $person, array $data): Person
    {
        $fill = collect(['mobile', 'email', 'cpf'])->filter(fn (string $field) => blank($person->{$field}) && filled($data[$field] ?? null))
            ->mapWithKeys(fn (string $field) => [$field => $data[$field]])->all();
        if ($fill !== []) {
            $person->update($fill);
        }

        return $person;
    }

    private function candidateQuery(User $user, Student $student, ?Dojo $dojo): Builder
    {
        $query = $this->associationPeople($student->association)->where('people.status', 'active')->whereKeyNot($student->person_id);
        if ($dojo === null) {
            return $query;
        }
        $students = $this->scope->canDojo($user, $dojo, 'student.manage')
            ? $this->scope->students($user, $dojo)->select('students.id')
            : ClassEnrollment::query()->select('student_id')->where('status', 'active')->coveringDate(today()->toDateString())
                ->whereIn('class_group_id', $this->teachers->groups($user)->where('dojo_id', $dojo->id)->select('id'));

        return $query->whereIn('people.id', StudentGuardian::query()->current()->whereIn('student_id', $students)->select('guardian_person_id'));
    }

    private function associationPeople(Association $association): Builder
    {
        return Person::query()->whereHas('associations', fn (Builder $q) => $q->whereKey($association->id)->where('association_person_roles.status', 'active'));
    }

    private function lockStudent(Association $association, Student $student): Student
    {
        $locked = Student::query()->lockForUpdate()->findOrFail($student->id);
        abort_unless($locked->association_id === $association->id && in_array($locked->status, ['active', 'pre_registration'], true), 404);

        return $locked;
    }

    private function lockLink(Association $association, Student $student, StudentGuardian $link, bool $lock = true): StudentGuardian
    {
        abort_unless($student->association_id === $association->id && $link->student_id === $student->id, 404);
        $locked = StudentGuardian::query()->when($lock, fn ($q) => $q->lockForUpdate())->current()->find($link->id);
        if ($locked === null) {
            throw ValidationException::withMessages(['guardian' => 'Este vínculo já foi encerrado.']);
        }

        return $locked;
    }

    /** @return array<string, bool> */
    private function flags(array $data): array
    {
        return collect(['is_legal_guardian', 'is_financial_guardian', 'is_primary', 'can_receive_notifications'])
            ->mapWithKeys(fn (string $flag) => [$flag => (bool) ($data[$flag] ?? false)])->all();
    }
}
