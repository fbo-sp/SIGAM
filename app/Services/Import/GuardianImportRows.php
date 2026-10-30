<?php

namespace App\Services\Import;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\Sports\GuardianService;
use App\Support\Normalization\InputNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Importação de responsáveis (decisão de Fabio em 30/10/2026): uma linha por vínculo, com o aluno do dojo do lote
 * (matrícula, ou nome e nascimento) e os dados do responsável. Ao processar, usa o GuardianService, que reaproveita a
 * pessoa pelo CPF ou pelo nome com o mesmo celular e não duplica vínculo vigente.
 */
class GuardianImportRows
{
    public const HEADERS = ['matricula_aluno', 'nome_aluno', 'nascimento_aluno', 'nome_responsavel', 'cpf', 'celular', 'email', 'parentesco', 'legal', 'financeiro', 'principal', 'avisos'];

    public const REQUIRED = ['nome_responsavel'];

    private ?int $dojoId = null;

    /** @var Collection<int, Student> */
    private Collection $students;

    public function __construct(private readonly GuardianService $guardians) {}

    public function load(Dojo $dojo): void
    {
        $this->dojoId = $dojo->id;
        $this->students = Student::query()->where('association_id', $dojo->association_id)->whereIn('status', ['active', 'pre_registration'])
            ->whereHas('dojoHistory', fn ($history) => $history->where('dojo_id', $dojo->id)->whereDate('start_date', '<=', today())
                ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today())))
            ->with(['person', 'guardians' => fn ($guardians) => $guardians->current()])->get();
    }

    /**
     * @param  array<string, array<string, int>>  $seen  pessoas e vínculos já vistos no arquivo
     * @return array{string, ?string, array<string, mixed>}
     */
    public function preview(Association $association, Dojo $dojo, array $raw, array &$seen): array
    {
        if ($this->dojoId !== $dojo->id) {
            $this->load($dojo);
        }
        $errors = [];
        $review = [];
        $line = (int) ($raw['_row_number'] ?? 0);
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($raw['nome_responsavel'] ?? '')));
        $cpf = InputNormalizer::digits($raw['cpf'] ?? null);
        $mobile = InputNormalizer::digits($raw['celular'] ?? null);
        $email = InputNormalizer::email($raw['email'] ?? null);
        [$student, $studentError] = $this->student($raw);
        if ($studentError) {
            $errors[] = $studentError;
        }
        if (mb_strlen($name) < 3) {
            $errors[] = 'Informe o nome completo do responsável.';
        }
        if ($cpf !== null && Validator::make(['cpf' => $cpf], ['cpf' => [new Cpf]])->fails()) {
            $errors[] = 'O CPF do responsável é inválido.';
        }
        if ($mobile !== null && ! in_array(strlen($mobile), [10, 11], true)) {
            $errors[] = 'O celular precisa ter DDD e 8 ou 9 números.';
        }
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'O e-mail do responsável é inválido.';
        }

        $personKey = $cpf ? 'cpf:'.$cpf : 'nome:'.StudentDuplicateFinder::name($name).'|'.$mobile;
        $sameAs = $seen['people'][$personKey] ?? null;
        $existing = null;
        if ($sameAs === null && $cpf !== null) {
            $existing = Person::query()->withTrashed()->where('cpf', $cpf)->first();
            if ($existing && ($existing->trashed() || $existing->status !== 'active' || ! $existing->associations()->whereKey($association->id)->wherePivot('status', 'active')->exists())) {
                $errors[] = 'Este CPF pertence a um cadastro que não está ativo nesta associação.';
            } elseif ($existing) {
                $review[] = "CPF já cadastrado ({$existing->full_name}): usa a pessoa existente.";
            }
        } elseif ($sameAs === null && $mobile !== null && $name !== '') {
            $existing = Person::query()->where('mobile', $mobile)->whereHas('associations', fn ($q) => $q->whereKey($association->id))->get()
                ->first(fn (Person $person) => StudentDuplicateFinder::name($person->full_name) === StudentDuplicateFinder::name($name));
            if ($existing) {
                $review[] = 'Mesmo nome e celular de uma pessoa já cadastrada: usa a pessoa existente.';
            }
        }
        if ($student && $existing && $student->person_id === $existing->id) {
            $errors[] = 'O aluno não pode ser o próprio responsável.';
        }
        $linkKey = $student ? $student->id.'|'.$personKey : null;
        if ($linkKey && isset($seen['links'][$linkKey])) {
            $errors[] = 'Este vínculo está repetido no arquivo (linha '.$seen['links'][$linkKey].').';
        }

        $normalized = [
            'student_id' => $student?->id, 'student_name' => $student?->person->full_name, 'full_name' => $name, 'cpf' => $cpf, 'mobile' => $mobile, 'email' => $email,
            'relationship_type' => $this->relationship($raw['parentesco'] ?? null), 'is_legal_guardian' => $this->yes($raw['legal'] ?? null),
            'is_financial_guardian' => $this->yes($raw['financeiro'] ?? null), 'is_primary' => $this->yes($raw['principal'] ?? null),
            'can_receive_notifications' => ($raw['avisos'] ?? '') === '' ? true : $this->yes($raw['avisos']),
            'same_as_row' => $sameAs, 'person_status' => $sameAs ? 'same' : ($existing ? 'existing' : 'new'),
        ];
        if ($errors === []) {
            $seen['people'][$personKey] ??= $line;
            $seen['links'][$linkKey] = $line;
            if ($existing && $student->guardians->contains('guardian_person_id', $existing->id)) {
                return ['already_processed', "{$student->person->full_name} já tem este responsável: vínculo não duplicado.", $normalized];
            }
        }

        return $errors !== [] ? ['invalid', implode(' ', array_unique($errors)), $normalized]
            : ($review !== [] ? ['review', implode(' ', array_unique($review)), $normalized] : ['ready', 'Pronta para importar.', $normalized]);
    }

    public function process(Association $association, ImportBatch $batch, User $user, ImportRow $row): StudentGuardian
    {
        $data = $row->normalized_payload;
        $student = Student::query()->where('association_id', $association->id)->findOrFail($data['student_id']);
        $payload = [...$data, 'mode' => 'new', 'start_date' => today()->toDateString(), 'release_portal' => false];
        if ($data['same_as_row'] ?? null) {
            $previous = ImportRow::query()->where('import_batch_id', $batch->id)->where('row_number', $data['same_as_row'])->where('result_type', StudentGuardian::class)->first();
            $person = $previous ? StudentGuardian::query()->whereKey($previous->result_id)->value('guardian_person_id') : null;
            if ($person) {
                $payload = [...$payload, 'mode' => 'existing', 'guardian_person_id' => $person];
            }
        }

        return $this->guardians->add($user, $association, $student, $payload)['link'];
    }

    /** Texto mostrado na prévia: "Marta Ribeiro → Clara Ribeiro". */
    public static function title(array $data): string
    {
        return trim(($data['full_name'] ?? '').' → '.($data['student_name'] ?? 'aluno não encontrado'));
    }

    /** @return array{?Student, ?string} */
    private function student(array $raw): array
    {
        $registration = trim((string) ($raw['matricula_aluno'] ?? ''));
        if ($registration !== '') {
            $found = $this->students->first(fn (Student $student) => mb_strtolower($student->registration_number) === mb_strtolower($registration));

            return $found ? [$found, null] : [null, 'A matrícula do aluno não foi encontrada neste dojo.'];
        }
        $name = StudentDuplicateFinder::name($raw['nome_aluno'] ?? null);
        if ($name === '') {
            return [null, 'Informe a matrícula ou o nome do aluno.'];
        }
        $birth = $this->date($raw['nascimento_aluno'] ?? null);
        $matches = $this->students->filter(fn (Student $student) => StudentDuplicateFinder::name($student->person->full_name) === $name
            && ($birth === null || $student->person->birth_date?->toDateString() === $birth))->values();

        return match ($matches->count()) {
            1 => [$matches->first(), null],
            0 => [null, 'Aluno não encontrado neste dojo pelo nome'.($birth ? ' e nascimento' : '').'.'],
            default => [null, 'Há mais de um aluno com este nome no dojo. Informe a matrícula ou o nascimento.'],
        };
    }

    private function date(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $text)->toDateString();
        }
        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);
                if ($date && $date->format($format) === $text) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function relationship(mixed $value): string
    {
        return match ($this->plain($value)) {
            'mae' => 'mother', 'pai' => 'father', 'avo', 'avoa', 'avo/avo' => 'grandparent', 'tutor', 'tutora' => 'tutor',
            'responsavel legal' => 'legal_guardian', 'contato de emergencia' => 'emergency_contact', default => 'other',
        };
    }

    private function yes(mixed $value): bool
    {
        return in_array($this->plain($value), ['sim', 's', '1', 'x', 'yes', 'true'], true);
    }

    private function plain(mixed $value): string
    {
        return mb_strtolower(Str::ascii(trim((string) $value)));
    }
}
