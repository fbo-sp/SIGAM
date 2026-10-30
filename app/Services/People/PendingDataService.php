<?php

namespace App\Services\People;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\Person;
use App\Models\Student;
use App\Models\User;
use App\Services\Reporting\ReportContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pendências de cadastro (decisões de Fabio em 29/10/2026): dados que faltam nos alunos ativos e em pré-cadastro,
 * calculados ao abrir a tela, sem tabela nem cron. O alcance é o mesmo dos aniversariantes (quem já vê os alunos:
 * associação, dojo ou as turmas do professor). Corrige quem já edita os dados pelas telas de edição existentes.
 */
class PendingDataService
{
    /** Pendência => [rótulo, rótulo curto para o portal, alta gravidade] */
    public const ITEMS = [
        'birth' => ['Sem nascimento', 'data de nascimento', true],
        'guardian' => ['Sem responsável legal', 'responsável legal', true],
        'emergency' => ['Sem contato de emergência', 'contato de emergência', false],
        'phone' => ['Sem telefone', 'telefone ou celular', false],
        'class' => ['Sem turma', 'turma', false],
        'cpf' => ['Sem CPF', 'CPF', false],
        'address' => ['Sem endereço', 'endereço', false],
    ];

    /** O portal só pede o que o aluno ou o responsável consegue preencher por lá. */
    public const PORTAL_ITEMS = ['emergency', 'phone'];

    public const LIMIT = 5000;

    public function __construct(private readonly BirthdayService $scopes) {}

    public function association(User $user, Association $association): ?ReportContext
    {
        return $this->scopes->association($user, $association);
    }

    public function dojo(User $user, Dojo $dojo): ?ReportContext
    {
        return $this->scopes->dojo($user, $dojo);
    }

    public function teacher(User $user): ?ReportContext
    {
        return $this->scopes->teacher($user);
    }

    /**
     * Alunos do alcance com ao menos uma pendência, ordenados por nome.
     *
     * @param  array{dojo_id?: ?int, class_group_id?: ?int, search?: ?string}  $filters
     * @return Collection<int, array{student: Student, items: list<string>, classes: string, dojo: ?Dojo}>
     */
    public function list(ReportContext $context, array $filters = []): Collection
    {
        $classes = $context->classes->keyBy('id');
        $query = Student::query()->where('association_id', $context->association->id)->whereIn('status', ['active', 'pre_registration']);
        if ($context->isTeacher()) {
            $query->where('status', 'active')->whereHas('enrollments', fn (Builder $enrollment) => $this->current($enrollment)->whereIn('class_group_id', $classes->keys()));
        } elseif ($context->dojo) {
            $query->whereHas('dojoHistory', fn (Builder $history) => $this->currentDojo($history, $context->dojo->id));
        }
        if ($filters['dojo_id'] ?? null) {
            $query->whereHas('dojoHistory', fn (Builder $history) => $this->currentDojo($history, $filters['dojo_id']));
        }
        if ($filters['class_group_id'] ?? null) {
            $query->whereHas('enrollments', fn (Builder $enrollment) => $this->current($enrollment)->where('class_group_id', $filters['class_group_id']));
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn (Builder $match) => $match->where('registration_number', 'like', '%'.$search.'%')
                ->orWhereHas('person', fn (Builder $person) => $person->where('full_name', 'like', '%'.$search.'%')->orWhere('social_name', 'like', '%'.$search.'%')));
        }

        return $this->withRelations($query, $context->isTeacher() ? $classes->keys() : null)->limit(self::LIMIT)->get()
            ->map(function (Student $student) use ($classes): array {
                $dojo = $student->dojoHistory->first()?->dojo;

                return [
                    'student' => $student,
                    'items' => $this->items($student),
                    'classes' => $student->enrollments->map(fn ($enrollment) => $classes[$enrollment->class_group_id]->name ?? $enrollment->classGroup?->name)->filter()->unique()->sort()->implode(', '),
                    'dojo' => $dojo,
                ];
            })->filter(fn (array $row) => $row['items'] !== [])
            ->sortBy(fn (array $row) => mb_strtolower($row['student']->person->social_name ?: $row['student']->person->full_name))->values();
    }

    /** @return array<string, int> contagem por pendência, na ordem de ITEMS */
    public function counts(Collection $rows): array
    {
        return collect(array_keys(self::ITEMS))->mapWithKeys(fn (string $key) => [$key => $rows->filter(fn (array $row) => in_array($key, $row['items'], true))->count()])
            ->filter()->all();
    }

    /** Pendências de um aluno já resolvido pela tela (ficha ou portal). @return list<string> */
    public function forStudent(Student $student): array
    {
        return $this->items($this->withRelations(Student::query()->whereKey($student->id))->firstOrFail());
    }

    /** @return list<string> */
    public function items(Student $student): array
    {
        /** @var Person $person */
        $person = $student->person;
        $guardians = $student->guardians;
        $items = [];
        if ($person->birth_date === null) {
            $items[] = 'birth';
        }
        // Menor de 18 anos ou aluno marcado como "precisa de responsável" (decisão de Fabio em 30/10/2026).
        if ($student->needsGuardian() && ! $guardians->contains('is_legal_guardian', true)) {
            $items[] = 'guardian';
        }
        if (blank($person->emergency_contact_name) || blank($person->emergency_contact_phone)) {
            $items[] = 'emergency';
        }
        if (blank($person->phone) && blank($person->mobile) && $guardians->every(fn ($guardian) => blank($guardian->person?->phone) && blank($guardian->person?->mobile))) {
            $items[] = 'phone';
        }
        if ($student->status === 'active' && $student->enrollments->isEmpty()) {
            $items[] = 'class';
        }
        if (blank($person->cpf)) {
            $items[] = 'cpf';
        }
        if (blank($person->street) || blank($person->city)) {
            $items[] = 'address';
        }

        return $items;
    }

    private function withRelations(Builder $query, ?Collection $classIds = null): Builder
    {
        return $query->with([
            'person',
            'guardians' => fn ($guardians) => $guardians->current()->with('person:id,phone,mobile'),
            'enrollments' => fn ($enrollment) => $this->current($enrollment)->when($classIds, fn ($scoped) => $scoped->whereIn('class_group_id', $classIds))->with('classGroup:id,name'),
            'dojoHistory' => fn ($history) => $history->whereDate('start_date', '<=', today())
                ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->with('dojo:id,name'),
        ]);
    }

    private function current($enrollment)
    {
        return $enrollment->where('status', 'active')->coveringDate(today()->toDateString());
    }

    private function currentDojo($history, int $dojoId)
    {
        return $history->where('dojo_id', $dojoId)->whereDate('start_date', '<=', today())
            ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }
}
