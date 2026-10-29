<?php

namespace App\Services\Sports;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\DirectRegistrationRequest;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Person;
use App\Models\Rank;
use App\Models\RegistrationCounter;
use App\Models\Student;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Access\TeacherInvitationService;
use App\Services\AuditService;
use App\Services\Classes\ClassGroupService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectRegistrationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TeacherInvitationService $invitations,
        private readonly ClassGroupService $classes,
    ) {}

    /** @return array{model: Student, request: DirectRegistrationRequest, replayed: bool} */
    public function registerStudent(Association $association, Dojo $dojo, User $actor, array $data): array
    {
        $this->assertActiveContext($association, $dojo);

        return DB::transaction(function () use ($association, $dojo, $actor, $data): array {
            [$submission, $replayed] = $this->submission($association, $dojo, $actor, 'student', $data['idempotency_key']);
            if ($replayed) {
                $student = Student::query()->where('association_id', $association->id)->findOrFail($submission->result_id);

                return ['model' => $student, 'request' => $submission, 'replayed' => true];
            }

            $person = $this->resolvePerson(
                $association,
                $data['person_mode'],
                $data['person_id'] ?? null,
                $data['person'] ?? [],
                (bool) ($data['confirm_existing_person'] ?? false),
                'aluno',
                'confirm_existing_person',
            );
            $martialArt = $this->martialArt($association, (int) $data['martial_art_id']);
            $rank = $this->rank($martialArt, $data['rank_id'] ?? null);
            $student = Student::query()->where('association_id', $association->id)->where('person_id', $person->id)->lockForUpdate()->first();
            $newStudent = $student === null;

            if ($newStudent) {
                $student = Student::query()->create([
                    'association_id' => $association->id,
                    'person_id' => $person->id,
                    'registration_number' => $this->nextRegistration($association, 'student'),
                    'entry_date' => $data['entry_date'],
                    'primary_dojo_id' => $dojo->id,
                    'status' => 'pre_registration',
                    'notes' => $data['notes'] ?? null,
                ]);
            } elseif (! in_array($student->status, ['pre_registration', 'active'], true)) {
                throw ValidationException::withMessages(['person_id' => 'O cadastro existente do aluno precisa ser regularizado antes de receber um novo vínculo.']);
            }

            $makePrimary = $newStudent || (bool) ($data['make_primary'] ?? false);
            if ($makePrimary) {
                $student->dojoHistory()->whereNull('end_date')->update(['is_primary' => false]);
                $student->update(['primary_dojo_id' => $dojo->id]);
            }
            $dojoLink = $student->dojoHistory()->where('dojo_id', $dojo->id)
                ->whereDate('start_date', '<=', $data['entry_date'])
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $data['entry_date']))
                ->first();
            if ($dojoLink === null) {
                $student->dojoHistory()->create([
                    'dojo_id' => $dojo->id,
                    'start_date' => $data['entry_date'],
                    'is_primary' => $makePrimary,
                    'reason' => $newStudent ? 'Cadastro direto' : 'Novo vínculo regular',
                ]);
            } elseif ($makePrimary && ! $dojoLink->is_primary) {
                $dojoLink->update(['is_primary' => true]);
            }

            $studentArt = $student->martialArts()->where('martial_art_id', $martialArt->id)->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $data['entry_date']))->first();
            if ($studentArt === null) {
                $studentArt = $student->martialArts()->create([
                    'martial_art_id' => $martialArt->id,
                    'start_date' => $data['entry_date'],
                    'status' => 'active',
                    'current_rank_id' => $rank?->id,
                ]);
                if ($rank !== null) {
                    $studentArt->rankHistory()->create([
                        'rank_id' => $rank->id,
                        'achieved_at' => $data['entry_date'],
                        'source_type' => 'initial_registration',
                    ]);
                }
            }

            if (($data['guardian_mode'] ?? 'none') !== 'none') {
                $guardian = $this->resolvePerson(
                    $association,
                    $data['guardian_mode'],
                    $data['guardian_person_id'] ?? null,
                    $data['guardian'] ?? [],
                    (bool) ($data['confirm_existing_guardian'] ?? false),
                    'responsável',
                    'confirm_existing_guardian',
                );
                if ($guardian->id === $person->id) {
                    throw ValidationException::withMessages(['guardian_person_id' => 'O aluno não pode ser o próprio responsável.']);
                }
                if ($data['guardian_is_primary'] ?? false) {
                    $student->guardians()->whereNull('end_date')->update(['is_primary' => false]);
                }
                $student->guardians()->firstOrCreate([
                    'guardian_person_id' => $guardian->id,
                    'start_date' => $data['entry_date'],
                ], [
                    'relationship_type' => $data['guardian_relationship'],
                    'is_legal_guardian' => (bool) ($data['guardian_is_legal'] ?? false),
                    'is_financial_guardian' => (bool) ($data['guardian_is_financial'] ?? false),
                    'is_primary' => (bool) ($data['guardian_is_primary'] ?? false),
                    'can_receive_notifications' => (bool) ($data['guardian_notifications'] ?? false),
                ]);
            }

            if ($data['finish_action'] === 'activate' && $student->status === 'pre_registration') {
                $this->activateStudent($association, $student);
            }
            if ($data['finish_action'] === 'activate') {
                $this->enrollInClasses($association, $dojo, $student, $data['class_group_ids'] ?? [], $data['entry_date']);
            }

            $submission->update(['result_type' => 'student', 'result_id' => $student->id, 'completed_at' => now()]);
            $this->audit->record('student.direct_registration_completed', $newStudent ? 'created' : 'updated', $student, null, [
                'status' => $student->status,
                'dojo_id' => $dojo->id,
            ], ['association_id' => $association->id, 'idempotency_request_id' => $submission->id]);

            return ['model' => $student, 'request' => $submission->fresh(), 'replayed' => false];
        }, 3);
    }

    /** @return array{model: TeacherProfile, request: DirectRegistrationRequest, replayed: bool} */
    public function registerTeacher(Association $association, User $actor, array $data): array
    {
        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association' => 'Ative a associação antes de cadastrar professores.']);
        }

        return DB::transaction(function () use ($association, $actor, $data): array {
            [$submission, $replayed] = $this->submission($association, null, $actor, 'teacher', $data['idempotency_key']);
            if ($replayed) {
                $profile = TeacherProfile::query()->findOrFail($submission->result_id);

                return ['model' => $profile, 'request' => $submission, 'replayed' => true];
            }

            $person = $this->resolvePerson(
                $association,
                $data['person_mode'],
                $data['person_id'] ?? null,
                $data['person'] ?? [],
                (bool) ($data['confirm_existing_person'] ?? false),
                'professor',
                'confirm_existing_person',
            );
            $martialArt = $this->martialArt($association, (int) $data['martial_art_id']);
            $rank = $this->rank($martialArt, $data['rank_id'] ?? null);
            $profile = TeacherProfile::query()->where('person_id', $person->id)->lockForUpdate()->first();
            $newProfile = $profile === null;
            if ($newProfile) {
                $profile = TeacherProfile::query()->create([
                    'person_id' => $person->id,
                    'registration_number' => $this->nextRegistration($association, 'teacher'),
                    'status' => 'active',
                    'notes' => $data['notes'] ?? null,
                ]);
            } elseif ($profile->status !== 'active') {
                throw ValidationException::withMessages(['person_id' => 'O perfil existente do professor precisa ser regularizado antes deste cadastro.']);
            }
            $profile->martialArts()->updateOrCreate(['martial_art_id' => $martialArt->id], [
                'rank_id' => $rank?->id,
                'federation_registration' => $data['federation_registration'] ?? null,
                'certification_expiration' => $data['certification_expiration'] ?? null,
                'status' => 'active',
            ]);
            TeacherAssociationMembership::query()->updateOrCreate([
                'association_id' => $association->id,
                'teacher_profile_id' => $profile->id,
            ], ['status' => 'active', 'source' => 'direct_registration']);

            if ($data['send_invitation'] ?? false) {
                $this->invitations->issue($actor, $association, $profile);
            }

            $submission->update(['result_type' => 'teacher', 'result_id' => $profile->id, 'completed_at' => now()]);
            $this->audit->record('teacher.direct_registration_completed', $newProfile ? 'created' : 'updated', $profile, null, [
                'status' => $profile->status,
            ], ['association_id' => $association->id, 'idempotency_request_id' => $submission->id]);

            return ['model' => $profile, 'request' => $submission->fresh(), 'replayed' => false];
        }, 3);
    }

    /** @return array{DirectRegistrationRequest, bool} */
    private function submission(Association $association, ?Dojo $dojo, User $actor, string $operation, string $key): array
    {
        $submission = DirectRegistrationRequest::query()->where('association_id', $association->id)
            ->where('operation', $operation)->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($submission !== null) {
            if ($submission->created_by_user_id !== $actor->id || $submission->dojo_id !== $dojo?->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Não foi possível reaproveitar esta solicitação. Atualize a página e tente novamente.']);
            }
            if ($submission->completed_at !== null && $submission->result_id !== null) {
                return [$submission, true];
            }

            throw ValidationException::withMessages(['idempotency_key' => 'Este cadastro ainda está sendo processado. Aguarde e tente novamente.']);
        }

        return [DirectRegistrationRequest::query()->create([
            'association_id' => $association->id,
            'dojo_id' => $dojo?->id,
            'created_by_user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
        ]), false];
    }

    /** Matricula o aluno recém-ativado nas turmas escolhidas no cadastro. Turma já cursada é ignorada. */
    private function enrollInClasses(Association $association, Dojo $dojo, Student $student, array $groupIds, string $entryDate): void
    {
        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            $group = ClassGroup::query()->where('association_id', $association->id)->where('dojo_id', $dojo->id)
                ->where('status', 'active')->find($groupId);
            if ($group === null) {
                throw ValidationException::withMessages(['class_group_ids' => 'Escolha somente turmas ativas do dojo do aluno.']);
            }
            if ($group->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists()) {
                continue;
            }
            try {
                $this->classes->enroll($association, $group, [
                    'student_id' => $student->id,
                    'start_date' => max($entryDate, $group->start_date->toDateString()),
                ]);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['class_group_ids' => "Turma {$group->name}: ".collect($exception->errors())->flatten()->first()]);
            }
        }
    }

    private function resolvePerson(Association $association, string $mode, mixed $personId, array $data, bool $confirmed, string $role, string $confirmationField): Person
    {
        if ($mode === 'existing') {
            return Person::query()->whereKey($personId)->where('status', 'active')
                ->whereHas('associations', fn ($query) => $query->whereKey($association->id)
                    ->where('association_person_roles.status', 'active'))
                ->firstOrFail();
        }

        $cpf = $data['cpf'] ?? null;
        if ($cpf !== null) {
            $existing = Person::query()->withTrashed()->where('cpf', $cpf)->lockForUpdate()->first();
            if ($existing !== null) {
                $accessible = ! $existing->trashed() && $existing->status === 'active'
                    && $existing->associations()->whereKey($association->id)->wherePivot('status', 'active')->exists();
                if (! $accessible) {
                    throw ValidationException::withMessages(['person.cpf' => 'Não foi possível concluir automaticamente com este CPF. Solicite uma revisão ao gestor da associação.']);
                }
                if (! $confirmed) {
                    throw ValidationException::withMessages([$confirmationField => 'Já existe uma pessoa com este CPF nesta associação. Marque a confirmação para reutilizar o cadastro sem duplicá-lo.']);
                }

                return $existing;
            }
        }

        $person = Person::query()->create([
            // Os formulários enviam só os campos validados; a importação de alunos também traz RG, telefone e endereço.
            ...Arr::only($data, ['full_name', 'cpf', 'birth_date', 'email', 'mobile', 'identity_document', 'phone', 'postal_code', 'street', 'number', 'complement', 'district', 'city', 'state']),
            'country' => 'BR',
            'status' => 'active',
        ]);
        $association->people()->attach($person->id, [
            'role_type' => $role,
            'start_date' => today()->toDateString(),
            'status' => 'active',
        ]);

        return $person;
    }

    private function nextRegistration(Association $association, string $entity): string
    {
        RegistrationCounter::query()->firstOrCreate(
            ['association_id' => $association->id, 'entity_type' => $entity],
            ['next_number' => 1],
        );
        $counter = RegistrationCounter::query()->where('association_id', $association->id)
            ->where('entity_type', $entity)->lockForUpdate()->firstOrFail();
        do {
            $number = $counter->next_number;
            $counter->increment('next_number');
            $registration = sprintf('%s-%06d-%06d', $entity === 'student' ? 'ALU' : 'PROF', $association->id, $number);
            $exists = $entity === 'student'
                ? Student::query()->where('association_id', $association->id)->where('registration_number', $registration)->withTrashed()->exists()
                : TeacherProfile::query()->where('registration_number', $registration)->exists();
        } while ($exists);

        return $registration;
    }

    private function martialArt(Association $association, int $id): MartialArt
    {
        return MartialArt::query()->whereKey($id)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->firstOrFail();
    }

    private function rank(MartialArt $martialArt, mixed $rankId): ?Rank
    {
        return $rankId ? Rank::query()->whereKey($rankId)->where('martial_art_id', $martialArt->id)->where('status', 'active')->firstOrFail() : null;
    }

    private function activateStudent(Association $association, Student $student): void
    {
        $student->load('person');
        if ($student->person->birth_date === null) {
            throw ValidationException::withMessages(['person.birth_date' => 'Informe a data de nascimento antes de concluir o cadastro.']);
        }
        if ($association->requires_minor_guardian && $student->person->birth_date->age < 18
            && ! $student->guardians()->where('is_legal_guardian', true)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->exists()) {
            throw ValidationException::withMessages(['guardian_is_legal' => 'Para concluir o cadastro de menor, informe um responsável legal vigente.']);
        }
        $student->update(['status' => 'active']);
    }

    private function assertActiveContext(Association $association, Dojo $dojo): void
    {
        if ($association->status !== 'active' || $dojo->association_id !== $association->id || $dojo->status !== 'active') {
            throw ValidationException::withMessages(['dojo' => 'Selecione um dojo ativo desta associação.']);
        }
    }
}
