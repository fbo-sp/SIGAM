<?php

namespace App\Services\Import;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\Person;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Procura, entre os alunos da associação, quem a linha da planilha já representa (decisões de Fabio em 29/10/2026):
 * CPF igual, depois RG igual (só letras e números), depois nome completo igual sem acentos e maiúsculas, conferindo a
 * data de nascimento quando os dois têm. Documentos preenchidos dos dois lados e diferentes descartam o candidato.
 */
class StudentDuplicateFinder
{
    /** Campo da pessoa => rótulo mostrado nas diferenças. Atualizar substitui só estes campos. */
    public const FIELDS = [
        'full_name' => 'Nome', 'cpf' => 'CPF', 'identity_document' => 'RG', 'birth_date' => 'Nascimento', 'email' => 'E-mail',
        'mobile' => 'Celular', 'phone' => 'Telefone', 'postal_code' => 'CEP', 'street' => 'Endereço', 'number' => 'Número',
        'complement' => 'Complemento', 'district' => 'Bairro', 'city' => 'Cidade', 'state' => 'UF',
    ];

    public const MATCH_LABELS = ['cpf' => 'Encontrado pelo CPF', 'rg' => 'Encontrado pelo RG', 'name' => 'Mesmo nome'];

    public const MATCH_NOTES = ['cpf' => 'encontrado pelo CPF', 'rg' => 'encontrado pelo RG', 'name' => 'mesmo nome'];

    private ?int $associationId = null;

    /** @var Collection<int, Student> */
    private Collection $students;

    /** Carrega os alunos uma vez por lote, para não consultar o banco a cada linha. */
    public function load(Association $association): void
    {
        $this->associationId = $association->id;
        $this->students = Student::query()->where('association_id', $association->id)
            ->with([
                'person',
                'dojoHistory' => fn ($history) => $history->whereDate('start_date', '<=', today())
                    ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->with('dojo:id,name'),
                'enrollments' => fn ($enrollment) => $enrollment->where('status', 'active')->coveringDate(today()->toDateString())->with('classGroup:id,name'),
            ])->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $person  dados normalizados da linha
     * @return array{student: Student, by: string}|null
     */
    public function find(Association $association, array $person): ?array
    {
        if ($this->associationId !== $association->id) {
            $this->load($association);
        }
        $cpf = $person['cpf'] ?? null;
        $rg = self::document($person['identity_document'] ?? null);
        $name = self::name($person['full_name'] ?? null);
        $birth = $person['birth_date'] ?? null;

        if ($cpf !== null && $found = $this->students->first(fn (Student $student) => $student->person?->cpf === $cpf)) {
            return ['student' => $found, 'by' => 'cpf'];
        }
        $compatible = fn (Student $student) => ! self::conflicts($cpf, $student->person->cpf);
        if ($rg !== '' && $found = $this->students->first(fn (Student $student) => $student->person && self::document($student->person->identity_document) === $rg && $compatible($student))) {
            return ['student' => $found, 'by' => 'rg'];
        }
        if ($name !== '' && $found = $this->students->first(fn (Student $student) => $student->person
            && self::name($student->person->full_name) === $name && $compatible($student)
            && ! self::conflicts($rg, self::document($student->person->identity_document))
            && ! self::conflicts($birth, $student->person->birth_date?->toDateString()))) {
            return ['student' => $found, 'by' => 'name'];
        }

        return null;
    }

    /**
     * Resumo guardado na linha do lote para a prévia: quem é, como foi encontrado e o que mudaria.
     *
     * @return array<string, mixed>
     */
    public function describe(Student $student, string $by, array $person, ?ClassGroup $group): array
    {
        $dojo = $student->dojoHistory->first()?->dojo?->name;
        $classes = $student->enrollments->map(fn ($enrollment) => $enrollment->classGroup?->name)->filter()->unique()->sort()->implode(', ');
        $summary = collect([$student->registration_number, $dojo, $classes ?: 'sem turma'])->filter()->implode(' · ');
        if ($by === 'name' && $student->person->birth_date) {
            $summary .= ' · nasc. '.$student->person->birth_date->format('d/m/Y');
        }
        $enroll = $group && ! $student->enrollments->contains('class_group_id', $group->id) ? $group->name : null;

        return [
            'student_id' => $student->id, 'registration_number' => $student->registration_number, 'by' => $by,
            'summary' => $summary, 'changes' => self::changes($student->person, $person), 'enroll' => $enroll,
            'kept_birth' => $by === 'name' && ($person['birth_date'] ?? null) === null && $student->person->birth_date
                ? $student->person->birth_date->format('d/m/Y') : null,
            'action' => 'ignore',
        ];
    }

    /**
     * Campos que a planilha substitui: só os preenchidos e diferentes. Célula vazia mantém o que existe.
     *
     * @return array<string, mixed>
     */
    public static function updates(Person $current, array $person): array
    {
        $updates = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $new = $person[$field] ?? null;
            if ($new === null || $new === '' || self::same($field, self::stored($current, $field), (string) $new)) {
                continue;
            }
            $updates[$field] = $new;
        }

        return $updates;
    }

    /** @return list<array{string, ?string, string}> rótulo, valor atual e valor da planilha, formatados */
    public static function changes(Person $current, array $person): array
    {
        return collect(self::updates($current, $person))->map(fn ($value, string $field) => [
            self::FIELDS[$field], self::format($field, self::stored($current, $field)), self::format($field, (string) $value),
        ])->values()->all();
    }

    /** RG só com pontuação diferente e nome só com acentos ou maiúsculas diferentes não contam como mudança. */
    private static function same(string $field, ?string $old, string $new): bool
    {
        return match ($field) {
            'identity_document' => self::document($old) === self::document($new),
            'full_name' => self::name($old) === self::name($new),
            default => $old === $new,
        };
    }

    private static function stored(Person $person, string $field): ?string
    {
        $value = $field === 'birth_date' ? $person->birth_date?->toDateString() : $person->getAttribute($field);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function format(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $field === 'birth_date' => date('d/m/Y', strtotime($value)),
            $field === 'cpf' && strlen($value) === 11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $value),
            $field === 'postal_code' && strlen($value) === 8 => preg_replace('/(\d{5})(\d{3})/', '$1-$2', $value),
            in_array($field, ['mobile', 'phone'], true) && in_array(strlen($value), [10, 11], true) => '('.substr($value, 0, 2).') '.substr($value, 2, -4).'-'.substr($value, -4),
            default => $value,
        };
    }

    private static function conflicts(?string $left, ?string $right): bool
    {
        return $left !== null && $left !== '' && $right !== null && $right !== '' && $left !== $right;
    }

    public static function document(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    public static function name(?string $value): string
    {
        return mb_strtolower(Str::ascii((string) preg_replace('/\s+/u', ' ', trim((string) $value))));
    }
}
