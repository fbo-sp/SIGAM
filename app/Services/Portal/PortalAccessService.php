<?php

namespace App\Services\Portal;

use App\Models\Charge;
use App\Models\Person;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortalAccessService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return Collection<int, Student> */
    public function studentsFor(User $user): Collection
    {
        if (! $user->association_id || ! $user->person_id) {
            return collect();
        }

        return $this->authorizedQuery($user)
            ->with(['person', 'primaryDojo'])
            ->orderBy('registration_number')
            ->get();
    }

    /** @var array<int, array{fee: bool, finance: bool}> */
    private array $financeCache = [];

    /**
     * O que o portal mostra do financeiro: "fee" quando o aluno paga mensalidade hoje (plano vigente e sem gratuidade
     * aprovada no dia) e "finance" quando há mensalidade, cobrança em aberto ou cobrança dos últimos 12 meses.
     *
     * @return array{fee: bool, finance: bool}
     */
    public function financeVisibility(Student $student): array
    {
        return $this->financeCache[$student->id] ??= (function () use ($student): array {
            $current = fn (Builder $query) => $query->whereDate('start_date', '<=', today())
                ->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
            $fee = $student->studentPlans()->where('status', 'active')->where($current)->exists()
                && $student->gratuityRequests()->where('status', 'approved')->where($current)->doesntExist();
            $finance = $fee || $student->charges()->where(fn (Builder $query) => $query
                ->where(fn (Builder $open) => $open->whereIn('status', Charge::OUTSTANDING_STATUSES)->where('balance_amount', '>', 0))
                ->orWhere(fn (Builder $recent) => $recent->where('status', '!=', 'cancelled')->whereDate('due_date', '>=', today()->subYear())))->exists();

            return ['fee' => $fee, 'finance' => $finance];
        })();
    }

    /** Aluno cujos "Meus dados" o nome no topo abre: o próprio cadastro de aluno, senão o primeiro aluno sob responsabilidade. */
    public function accountStudent(User $user): ?Student
    {
        if (! $user->hasPermission('portal.access', $user->association_id)) {
            return null;
        }
        $students = $this->studentsFor($user);

        return $students->firstWhere('person_id', $user->person_id) ?? $students->first();
    }

    public function resolveStudent(User $user, Student|int $student): Student
    {
        abort_unless($user->association_id && $user->person_id, 404);
        $studentId = $student instanceof Student ? $student->getKey() : $student;
        $resolved = $this->authorizedQuery($user)->find($studentId);

        abort_unless($resolved, 404);

        return $resolved;
    }

    public function canEditContact(User $user, Student $student): bool
    {
        if ($student->person_id === $user->person_id) {
            return true;
        }

        return $student->guardians()
            ->where('guardian_person_id', $user->person_id)
            ->where('is_legal_guardian', true)
            ->whereDate('start_date', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->exists();
    }

    public function updateContact(User $user, Student $student, array $data): Person
    {
        $student = $this->resolveStudent($user, $student);
        abort_unless($this->canEditContact($user, $student), 403, 'Somente o próprio aluno ou responsável legal pode alterar os contatos.');

        return DB::transaction(function () use ($user, $student, $data): Person {
            $person = Person::query()->lockForUpdate()->findOrFail($student->person_id);
            $allowed = collect($data)->only(['email', 'phone', 'mobile', 'emergency_contact_name', 'emergency_contact_phone'])->all();
            $changedFields = collect($allowed)->filter(fn ($value, string $field) => $person->{$field} !== $value)->keys()->values()->all();

            if ($changedFields !== []) {
                $person->update($allowed);
                $this->audit->record(
                    'portal.contact.updated',
                    'update',
                    $person,
                    ['fields' => $changedFields],
                    ['fields' => $changedFields],
                    ['association_id' => $user->association_id, 'student_id' => $student->id],
                );
            }

            return $person->refresh();
        }, 3);
    }

    private function authorizedQuery(User $user): Builder
    {
        return Student::query()
            ->where('association_id', $user->association_id)
            ->where(function (Builder $query) use ($user): void {
                $query->where('person_id', $user->person_id)
                    ->orWhereHas('guardians', function (Builder $guardians) use ($user): void {
                        $guardians->where('guardian_person_id', $user->person_id)
                            ->whereDate('start_date', '<=', today())
                            ->where(fn (Builder $period) => $period->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
                    });
            });
    }
}
