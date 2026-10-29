<?php

namespace App\Services\Import;

use App\Models\Association;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MartialArt;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Student;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\Access\OperationalScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\SessionGenerator;
use App\Services\AuditService;
use App\Services\Classes\ClassGroupService;
use App\Services\People\PersonService;
use App\Services\Sports\DirectRegistrationService;
use App\Support\Import\SpreadsheetReader;
use App\Support\Normalization\InputNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssistedImportService
{
    public const STUDENT_HEADERS = ['nome_completo', 'cpf', 'data_nascimento', 'email', 'celular', 'data_entrada', 'modalidade', 'graduacao', 'turma', 'ativar', 'nome_responsavel', 'cpf_responsavel', 'parentesco', 'responsavel_legal', 'observacoes', 'rg', 'telefone', 'cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    /** Situações que ainda serão processadas; "duplicate" é aluno já cadastrado aguardando a escolha da prévia. */
    public const PENDING = ['ready', 'review', 'duplicate'];

    public const ATTENDANCE_HEADERS = ['matricula_aluno', 'turma', 'data_aula', 'horario_inicio', 'situacao', 'observacoes'];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly DirectRegistrationService $registration,
        private readonly ClassGroupService $classes,
        private readonly AttendanceService $attendance,
        private readonly OperationalScope $scope,
        private readonly AuditService $audit,
        private readonly SessionGenerator $sessions,
        private readonly StudentDuplicateFinder $duplicates,
        private readonly PersonService $people,
    ) {}

    public function batches(Association $association, User $user): Builder
    {
        $dojoIds = $this->availableDojos($association, $user)->pluck('id');

        return ImportBatch::query()->where('association_id', $association->id)->whereIn('dojo_id', $dojoIds);
    }

    public function availableDojos(Association $association, User $user)
    {
        return Dojo::query()->where('association_id', $association->id)->where('status', 'active')->get()
            ->filter(fn (Dojo $dojo) => $this->canImport($association, $dojo, $user))->values();
    }

    /** @return array{batch: ImportBatch, replayed: bool} */
    public function create(Association $association, Dojo $dojo, User $user, string $type, UploadedFile $file): array
    {
        $this->authorize($association, $dojo, $user, $type);
        if (! in_array($type, ['students', 'attendance'], true)) {
            throw ValidationException::withMessages(['import_type' => 'Escolha alunos ou presenças.']);
        }
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Use um arquivo CSV ou XLSX.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = ImportBatch::query()->where([
            'association_id' => $association->id, 'dojo_id' => $dojo->id, 'import_type' => $type, 'file_hash' => $hash,
        ])->first();
        if ($existing !== null) {
            return ['batch' => $existing, 'replayed' => true];
        }

        $sheet = $type === 'students' ? 'Alunos' : 'Presenças';
        $rows = $this->reader->read($file->getRealPath(), $extension, $sheet);
        $required = $type === 'students' ? ['nome_completo', 'data_entrada', 'modalidade'] : ['matricula_aluno', 'turma', 'data_aula', 'horario_inicio', 'situacao'];
        $present = array_keys($rows[0]['values'] ?? []);
        if ($missing = array_diff($required, $present)) {
            throw ValidationException::withMessages(['file' => 'Faltam colunas obrigatórias: '.implode(', ', $missing).'.']);
        }

        $storedName = Str::uuid().'.'.$extension;
        $path = $file->storeAs("imports/{$association->id}", $storedName, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível guardar o arquivo com segurança.']);
        }

        try {
            $batch = DB::transaction(function () use ($association, $dojo, $user, $type, $file, $hash, $storedName, $path, $rows): ImportBatch {
                $batch = ImportBatch::query()->create([
                    'association_id' => $association->id, 'dojo_id' => $dojo->id, 'created_by_user_id' => $user->id,
                    'import_type' => $type, 'original_name' => basename($file->getClientOriginalName()), 'stored_name' => $storedName,
                    'storage_path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(), 'file_hash' => $hash, 'status' => 'reviewing',
                ]);
                $seen = [];
                if ($type === 'students') {
                    $this->duplicates->load($association);
                }
                foreach ($rows as $source) {
                    $values = [...$source['values'], '_row_number' => $source['row_number']];
                    [$status, $message, $normalized] = $type === 'students'
                        ? $this->studentRow($association, $dojo, $values, $seen)
                        : $this->attendanceRow($association, $dojo, $values, $seen);
                    $batch->rows()->create([
                        'row_number' => $source['row_number'], 'row_hash' => hash('sha256', json_encode($source['values'])),
                        'raw_payload' => $source['values'], 'normalized_payload' => $normalized, 'status' => $status,
                        'initial_status' => $status, 'message' => $message,
                    ]);
                }
                $this->recount($batch);
                $this->audit->record('assisted_import.preview_created', 'created', $batch, null, [
                    'import_type' => $type, 'dojo_id' => $dojo->id, 'total_rows' => count($rows),
                ], ['association_id' => $association->id]);

                return $batch->fresh();
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return ['batch' => $batch, 'replayed' => false];
    }

    /**
     * @param  array<int|string, string>  $choices  escolha por linha já cadastrada: ignore, update ou new (só homônimo)
     * @return array{processed: int, remaining: int, completed: bool}
     */
    public function process(Association $association, ImportBatch $batch, User $user, bool $confirmReviews, array $choices = []): array
    {
        $this->authorizeBatch($association, $batch, $user);
        if ($batch->status === 'expired') {
            throw ValidationException::withMessages(['batch' => 'Este lote expirou. Corrija a planilha original e faça um novo envio.']);
        }
        if ($batch->status === 'completed') {
            return ['processed' => 0, 'remaining' => 0, 'completed' => true];
        }
        if ($batch->review_rows > 0 && $batch->confirmed_at === null && ! $confirmReviews) {
            throw ValidationException::withMessages(['confirm_reviews' => 'Confirme que revisou as linhas sinalizadas antes de continuar.']);
        }
        $this->saveChoices($batch, $choices);
        $batch->update(['status' => 'processing', 'confirmed_at' => $batch->confirmed_at ?: now()]);
        $rows = $batch->rows()->whereIn('status', self::PENDING)->orderBy('row_number')->limit(100)->get();
        $processed = 0;
        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($association, $batch, $row, $user): void {
                    $locked = ImportRow::query()->lockForUpdate()->findOrFail($row->id);
                    if (! in_array($locked->status, self::PENDING, true)) {
                        return;
                    }
                    if ($locked->status === 'duplicate') {
                        $this->processDuplicate($association, $batch, $user, $locked);

                        return;
                    }
                    $wasReview = $locked->status === 'review';
                    $previousMessage = $locked->message;
                    $result = $batch->import_type === 'students'
                        ? $this->processStudent($association, $batch->dojo, $user, $locked->normalized_payload)
                        : $this->processAttendance($association, $user, $locked->normalized_payload);
                    $locked->update([
                        'status' => 'processed',
                        'message' => $wasReview ? 'Importado após revisão. '.$previousMessage : 'Importado com sucesso.',
                        'result_type' => $result::class, 'result_id' => $result->getKey(), 'processed_at' => now(),
                    ]);
                }, 3);
                $processed++;
            } catch (Throwable $exception) {
                $message = $exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->first()
                    : 'Não foi possível processar esta linha.';
                $row->update(['status' => 'failed', 'message' => Str::limit((string) $message, 1000)]);
                report($exception);
            }
        }
        $this->recount($batch);
        $remaining = $batch->rows()->whereIn('status', self::PENDING)->count();
        $completed = $remaining === 0;
        $batch->update(['status' => $completed ? 'completed' : 'processing', 'completed_at' => $completed ? now() : null]);
        $this->audit->record('assisted_import.processed', 'updated', $batch, null, [
            'processed_now' => $processed, 'remaining' => $remaining, 'completed' => $completed,
        ], ['association_id' => $association->id]);

        return compact('processed', 'remaining', 'completed');
    }

    /** Guarda na linha a escolha feita na prévia; "new" só vale para quem foi encontrado pelo nome (homônimo). */
    private function saveChoices(ImportBatch $batch, array $choices): void
    {
        if ($choices === []) {
            return;
        }
        $batch->rows()->where('status', 'duplicate')->whereIn('id', array_map('intval', array_keys($choices)))->get()
            ->each(function (ImportRow $row) use ($choices): void {
                $payload = $row->normalized_payload;
                $action = (string) $choices[$row->id];
                if (! in_array($action, ['ignore', 'update', 'new'], true) || ($action === 'new' && $payload['duplicate']['by'] !== 'name')) {
                    throw ValidationException::withMessages(["acoes.{$row->id}" => "Escolha inválida para a linha {$row->row_number}."]);
                }
                if (($payload['duplicate']['action'] ?? 'ignore') !== $action) {
                    $payload['duplicate']['action'] = $action;
                    $row->update(['normalized_payload' => $payload]);
                }
            });
    }

    /** Executa a escolha da linha já cadastrada. Chamado com a linha travada; a situação final impede repetir o efeito. */
    private function processDuplicate(Association $association, ImportBatch $batch, User $user, ImportRow $row): void
    {
        $data = $row->normalized_payload;
        $duplicate = $data['duplicate'];
        $action = $duplicate['action'] ?? 'ignore';
        $student = Student::query()->where('association_id', $association->id)->findOrFail($duplicate['student_id']);
        if ($action === 'ignore') {
            $row->update(['status' => 'ignored', 'message' => "Ignorada: já cadastrado como {$student->registration_number}.",
                'result_type' => Student::class, 'result_id' => $student->id, 'processed_at' => now()]);

            return;
        }
        if ($action === 'new') {
            $created = $this->processStudent($association, $batch->dojo, $user, $data);
            $row->update(['status' => 'processed', 'message' => "Cadastrado como novo aluno (homônimo de {$student->registration_number}).",
                'result_type' => Student::class, 'result_id' => $created->id, 'processed_at' => now()]);

            return;
        }

        $person = Person::query()->lockForUpdate()->findOrFail($student->person_id);
        $updates = StudentDuplicateFinder::updates($person, $data['person']);
        if (isset($updates['cpf']) && Person::query()->withTrashed()->where('cpf', $updates['cpf'])->whereKeyNot($person->id)->exists()) {
            throw ValidationException::withMessages(['cpf' => 'O CPF da planilha já pertence a outra pessoa. Revise a linha.']);
        }
        if ($updates !== []) {
            $this->people->update($association, $person, $updates);
        }
        $parts = [$updates === [] ? "Nada a atualizar em {$student->registration_number}." : "Atualizado {$student->registration_number}: "
            .implode(', ', array_map(fn (string $field) => mb_strtolower(StudentDuplicateFinder::FIELDS[$field]), array_keys($updates))).'.'];
        $parts[] = $this->enrollDuplicate($association, $student->fresh(), $data);
        $row->update(['status' => 'processed', 'message' => Str::limit(implode(' ', array_filter($parts)), 1000),
            'result_type' => Student::class, 'result_id' => $student->id, 'processed_at' => now()]);
    }

    /**
     * Matricula na turma da planilha quando o aluno ainda não está nela, sem encerrar outras matrículas. Se a matrícula
     * não for possível (aluno inativo, outro dojo, outra modalidade), os dados continuam atualizados e o motivo fica na linha.
     */
    private function enrollDuplicate(Association $association, Student $student, array $data): ?string
    {
        if (! $data['class_group_id']) {
            return null;
        }
        $group = ClassGroup::query()->where('association_id', $association->id)->findOrFail($data['class_group_id']);
        $today = today()->toDateString();
        if ($student->enrollments()->where('class_group_id', $group->id)->where('status', 'active')->coveringDate($today)->exists()) {
            return null;
        }
        if ($student->status !== 'active') {
            return "Não matriculado em {$group->name}: o aluno não está ativo.";
        }
        $starts = array_filter([
            $data['entry_date'], $group->start_date?->toDateString(),
            $student->dojoHistory()->where('dojo_id', $group->dojo_id)->whereDate('start_date', '<=', $today)
                ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->min('start_date'),
            $student->martialArts()->where('martial_art_id', $group->martial_art_id)->where('status', 'active')->whereDate('start_date', '<=', $today)
                ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->min('start_date'),
        ]);
        $start = collect($starts)->map(fn ($date) => Carbon::parse($date)->toDateString())->max();
        try {
            $this->classes->enroll($association, $group, ['student_id' => $student->id, 'start_date' => $start]);
        } catch (ValidationException $exception) {
            return "Não matriculado em {$group->name}: ".collect($exception->errors())->flatten()->first();
        }

        return "Matriculado em {$group->name}.";
    }

    public function authorizeBatch(Association $association, ImportBatch $batch, User $user): void
    {
        abort_unless($batch->association_id === $association->id, 404);
        $this->authorize($association, $batch->dojo, $user, $batch->import_type);
    }

    private function canImport(Association $association, Dojo $dojo, User $user): bool
    {
        if ($dojo->association_id !== $association->id) {
            return false;
        }

        return $user->hasPermission('import.manage', $association->id)
            || $this->scope->canDojo($user, $dojo, 'import.manage');
    }

    private function authorize(Association $association, Dojo $dojo, User $user, string $type): void
    {
        abort_unless($this->canImport($association, $dojo, $user), 403);
        $permission = $type === 'attendance' ? 'attendance.manage' : 'student.manage';
        abort_unless($user->hasPermission($permission, $association->id) || $this->scope->canDojo($user, $dojo, $permission), 403);
    }

    /** @return array{string, ?string, array<string, mixed>} */
    private function studentRow(Association $association, Dojo $dojo, array $raw, array &$seen): array
    {
        $errors = [];
        $review = [];
        $cpf = InputNormalizer::digits($raw['cpf'] ?? null);
        $guardianCpf = InputNormalizer::digits($raw['cpf_responsavel'] ?? null);
        $birth = $this->date($raw['data_nascimento'] ?? null);
        $entry = $this->date($raw['data_entrada'] ?? null);
        if (trim((string) ($raw['nome_completo'] ?? '')) === '') {
            $errors[] = 'Informe o nome completo.';
        }
        if (mb_strlen(trim((string) ($raw['nome_completo'] ?? ''))) > 255) {
            $errors[] = 'O nome completo deve ter até 255 caracteres.';
        }
        $birthText = trim((string) ($raw['data_nascimento'] ?? ''));
        if ($birthText !== '' && $birth === null) {
            $errors[] = 'Informe a data de nascimento no formato DD/MM/AAAA.';
        }
        if ($birth !== null && Carbon::parse($birth)->isFuture()) {
            $errors[] = 'A data de nascimento não pode estar no futuro.';
        }
        if ($entry === null) {
            $errors[] = 'Informe a data de entrada no formato DD/MM/AAAA.';
        }
        if ($cpf !== null && Validator::make(['cpf' => $cpf], ['cpf' => [new Cpf]])->fails()) {
            $errors[] = 'O CPF do aluno é inválido.';
        }
        if ($guardianCpf !== null && Validator::make(['cpf' => $guardianCpf], ['cpf' => [new Cpf]])->fails()) {
            $errors[] = 'O CPF do responsável é inválido.';
        }
        $email = $this->nullableLower($raw['email'] ?? null);
        if ($email !== null && Validator::make(['email' => $email], ['email' => ['email:rfc', 'max:255']])->fails()) {
            $errors[] = 'O e-mail do aluno é inválido.';
        }
        $mobile = InputNormalizer::digits($raw['celular'] ?? null);
        if ($mobile !== null && ! in_array(strlen($mobile), [10, 11], true)) {
            $errors[] = 'O celular deve ter 10 ou 11 dígitos.';
        }
        $phone = InputNormalizer::digits($raw['telefone'] ?? null);
        if ($phone !== null && ! in_array(strlen($phone), [10, 11], true)) {
            $errors[] = 'O telefone deve ter 10 ou 11 dígitos, com DDD.';
        }
        $postalCode = InputNormalizer::digits($raw['cep'] ?? null);
        if ($postalCode !== null && strlen($postalCode) !== 8) {
            $errors[] = 'O CEP deve ter 8 dígitos.';
        }
        $state = $this->nullable($raw['uf'] ?? null);
        $state = $state === null ? null : mb_strtoupper($state);
        if ($state !== null && ! preg_match('/^[A-Z]{2}$/', $state)) {
            $errors[] = 'A UF deve ter 2 letras, como SP.';
        }
        // Endereço e RG são opcionais (decisão de Fabio em 29/10/2026); textos acima do tamanho da coluna recusam a linha.
        $address = ['identity_document' => 40, 'street' => 255, 'number' => 30, 'complement' => 255, 'district' => 255, 'city' => 255];
        $columns = ['identity_document' => 'rg', 'street' => 'endereco', 'number' => 'numero', 'complement' => 'complemento', 'district' => 'bairro', 'city' => 'cidade'];
        $extra = [];
        foreach ($address as $field => $limit) {
            $extra[$field] = $this->nullable($raw[$columns[$field]] ?? null);
            if ($extra[$field] !== null && mb_strlen($extra[$field]) > $limit) {
                $errors[] = "A coluna {$columns[$field]} deve ter até {$limit} caracteres.";
            }
        }
        $identity = $cpf ?: mb_strtolower(trim((string) ($raw['nome_completo'] ?? ''))).'|'.$birth;
        if (isset($seen[$identity])) {
            $errors[] = "Cadastro repetido no arquivo (linha {$seen[$identity]}).";
        }
        $seen[$identity] = $raw['_row_number'] ?? '?';

        $art = $this->namedArt($association, $raw['modalidade'] ?? '');
        if ($art === null) {
            $errors[] = 'A modalidade não foi encontrada nesta associação.';
        }
        $rank = $art ? $this->namedRank($art, $raw['graduacao'] ?? '') : null;
        if ($art && trim((string) ($raw['graduacao'] ?? '')) !== '' && $rank === null) {
            $errors[] = 'A graduação não foi encontrada na modalidade.';
        }
        $group = trim((string) ($raw['turma'] ?? '')) === '' ? null : $this->namedGroup($association, $dojo, $raw['turma']);
        if (trim((string) ($raw['turma'] ?? '')) !== '' && $group === null) {
            $errors[] = 'A turma não foi encontrada neste dojo.';
        }
        $existing = $cpf ? Person::query()->withTrashed()->where('cpf', $cpf)->first() : null;
        $personData = ['full_name' => trim((string) ($raw['nome_completo'] ?? '')), 'cpf' => $cpf, 'birth_date' => $birth,
            'email' => $email, 'mobile' => $mobile, 'phone' => $phone, 'postal_code' => $postalCode, 'state' => $state] + $extra;
        $match = $this->duplicates->find($association, $personData);
        if ($existing && ($match['by'] ?? null) !== 'cpf') {
            $accessible = ! $existing->trashed() && $existing->status === 'active' && $existing->associations()->whereKey($association->id)->wherePivot('status', 'active')->exists();
            $accessible ? $review[] = 'O CPF já existe e o cadastro será reutilizado.' : $errors[] = 'Este CPF exige revisão pelo gestor da associação.';
        }
        $guardian = $guardianCpf ? Person::query()->withTrashed()->where('cpf', $guardianCpf)->first() : null;
        if ($guardian) {
            $accessible = ! $guardian->trashed() && $guardian->status === 'active' && $guardian->associations()->whereKey($association->id)->wherePivot('status', 'active')->exists();
            $accessible ? $review[] = 'O responsável existente será reutilizado.' : $errors[] = 'O CPF do responsável exige revisão pelo gestor da associação.';
        }
        $activate = $this->yes($raw['ativar'] ?? 'sim');
        // Matrícula em turma exige aluno ativo: no pré-cadastro a turma fica para depois da ativação.
        if (! $activate && $group !== null) {
            $review[] = "A turma {$group->name} fica para matricular depois de ativar o aluno.";
        }
        $minor = $birth && Carbon::parse($birth)->age < 18;
        if ($activate && $association->requires_minor_guardian && $minor && trim((string) ($raw['nome_responsavel'] ?? '')) === '' && ! $guardian) {
            $errors[] = 'Informe um responsável legal para ativar o aluno menor de idade.';
        }

        $normalized = [
            'idempotency_key' => (string) Str::uuid(), 'person_id' => $existing?->id,
            'person' => $personData,
            'entry_date' => $entry, 'martial_art_id' => $art?->id, 'rank_id' => $rank?->id, 'class_group_id' => $group?->id,
            'activate' => $activate, 'notes' => $this->nullable($raw['observacoes'] ?? null), 'guardian_person_id' => $guardian?->id,
            'guardian' => ['full_name' => trim((string) ($raw['nome_responsavel'] ?? '')), 'cpf' => $guardianCpf],
            'guardian_relationship' => $this->relationship($raw['parentesco'] ?? null), 'guardian_is_legal' => $this->yes($raw['responsavel_legal'] ?? null),
        ];
        if ($match && $errors === []) {
            $normalized['duplicate'] = $this->duplicates->describe($match['student'], $match['by'], $personData, $group);
            $verb = $match['by'] === 'name' ? 'Parece ser' : 'Já cadastrado como';

            return ['duplicate', "{$verb} {$match['student']->registration_number} (".StudentDuplicateFinder::MATCH_NOTES[$match['by']].').', $normalized];
        }

        return $this->rowResult($errors, $review, $normalized);
    }

    /** @return array{string, ?string, array<string, mixed>} */
    private function attendanceRow(Association $association, Dojo $dojo, array $raw, array &$seen): array
    {
        $errors = [];
        $review = [];
        $date = $this->date($raw['data_aula'] ?? null);
        $time = $this->time($raw['horario_inicio'] ?? null);
        $status = $this->attendanceStatus($raw['situacao'] ?? null);
        $student = Student::query()->where('association_id', $association->id)->where('registration_number', trim((string) ($raw['matricula_aluno'] ?? '')))->first();
        $group = $this->namedGroup($association, $dojo, $raw['turma'] ?? '');
        if ($student === null) {
            $errors[] = 'A matrícula do aluno não foi encontrada.';
        }
        if ($group === null) {
            $errors[] = 'A turma não foi encontrada neste dojo.';
        }
        if ($date === null) {
            $errors[] = 'Informe a data da aula no formato DD/MM/AAAA.';
        }
        if ($time === null) {
            $errors[] = 'Informe o início no formato HH:MM.';
        }
        if ($status === null) {
            $errors[] = 'Use presente, falta, falta justificada, reposição ou experimental.';
        }
        $session = ($group && $date && $time) ? ClassSession::query()->where('class_group_id', $group->id)->whereDate('session_date', $date)->whereTime('start_time', $time)->first() : null;
        $createSession = false;
        if ($date !== null && Carbon::parse($date)->isAfter(today())) {
            $errors[] = 'A aula ainda não aconteceu. Importe presenças somente de aulas até hoje.';
        } elseif ($group && $date && $time && $session === null) {
            // Aula passada que ainda não existe: é criada pelo horário da turma ao processar a linha.
            $missing = $this->missingPastSession($group, $date, $time);
            if (is_string($missing)) {
                $errors[] = $missing;
            } else {
                $session = $missing;
                $createSession = true;
                $review[] = 'A aula será criada pelo horário da turma.';
            }
        }
        if ($session && ($session->attendance_state !== 'open' || in_array($session->status, ['cancelled', 'rescheduled'], true))) {
            $errors[] = 'A aula está fechada, cancelada ou reagendada.';
        }
        if ($session && $student && ! $this->attendance->roster($session->load('classGroup'))->contains(fn ($item) => $item['student']->id === $student->id)) {
            $errors[] = 'O aluno não está matriculado nem autorizado como visitante nesta aula.';
        }
        $key = implode('|', [$student?->id, $group?->id, $date, $time]);
        if ($student && $session && isset($seen[$key])) {
            $errors[] = 'A presença está repetida no arquivo.';
        }
        if ($student && $session) {
            $seen[$key] = true;
        }
        $record = ($student && $session) ? AttendanceRecord::query()->where('class_session_id', $session->id)->where('student_id', $student->id)->first() : null;
        if ($record && $record->status === $status) {
            return ['already_processed', 'Esta presença já estava registrada com a mesma situação.', ['student_id' => $student->id, 'session_id' => $session->id, 'status' => $status]];
        }
        if ($record) {
            $review[] = 'A situação já registrada será atualizada.';
        }

        return $this->rowResult($errors, $review, [
            'student_id' => $student?->id, 'session_id' => $createSession ? null : $session?->id, 'status' => $status, 'notes' => $this->nullable($raw['observacoes'] ?? null),
            'class_group_id' => $group?->id, 'session_date' => $date, 'start_time' => $time,
        ]);
    }

    /** Aula passada ainda não gerada: devolve a aula (não salva) a criar pelo horário da turma ou o motivo do erro. */
    private function missingPastSession(ClassGroup $group, string $date, string $time): ClassSession|string
    {
        $day = Carbon::parse($date);
        if ($day->lt($group->start_date) || ($group->end_date && $day->gt($group->end_date))) {
            return 'A data está fora do período da turma (início em '.$group->start_date->format('d/m/Y').'). Use "Gerar aulas passadas" na turma para recuar o início.';
        }
        $schedule = $group->schedules()->whereIn('status', ['active', 'replaced'])->where('weekday', $day->dayOfWeekIso)
            ->whereTime('start_time', $time)->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))->first();
        if ($schedule === null) {
            return 'A turma não tem horário nesse dia da semana e início, então a aula não pode ser criada.';
        }

        return (new ClassSession(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => $schedule->start_time, 'status' => 'planned', 'attendance_state' => 'open']))->setRelation('classGroup', $group);
    }

    private function processStudent(Association $association, Dojo $dojo, User $user, array $data): Student
    {
        $guardianMode = $data['guardian_person_id'] ? 'existing' : (($data['guardian']['full_name'] ?? '') !== '' ? 'new' : 'none');
        $result = $this->registration->registerStudent($association, $dojo, $user, [
            'idempotency_key' => $data['idempotency_key'], 'person_mode' => $data['person_id'] ? 'existing' : 'new',
            'person_id' => $data['person_id'], 'person' => $data['person'], 'confirm_existing_person' => (bool) $data['person_id'],
            'martial_art_id' => $data['martial_art_id'], 'rank_id' => $data['rank_id'], 'entry_date' => $data['entry_date'],
            'make_primary' => true, 'notes' => $data['notes'], 'finish_action' => $data['activate'] ? 'activate' : 'pre_registration',
            'guardian_mode' => $guardianMode, 'guardian_person_id' => $data['guardian_person_id'], 'guardian' => $data['guardian'],
            'confirm_existing_guardian' => (bool) $data['guardian_person_id'], 'guardian_relationship' => $data['guardian_relationship'],
            'guardian_is_legal' => $data['guardian_is_legal'], 'guardian_is_financial' => false, 'guardian_is_primary' => true, 'guardian_notifications' => true,
        ]);
        $student = $result['model'];
        if ($data['class_group_id'] && $data['activate'] && ! $student->enrollments()->where('class_group_id', $data['class_group_id'])->where('status', 'active')->exists()) {
            $this->classes->enroll($association, ClassGroup::query()->findOrFail($data['class_group_id']), ['student_id' => $student->id, 'start_date' => $data['entry_date']]);
        }

        return $student;
    }

    private function processAttendance(Association $association, User $user, array $data): AttendanceRecord
    {
        if (($data['session_id'] ?? null) === null) {
            $group = ClassGroup::query()->where('association_id', $association->id)->findOrFail($data['class_group_id']);
            $this->sessions->generateForGroup($group, Carbon::parse($data['session_date']), Carbon::parse($data['session_date']));
            $data['session_id'] = ClassSession::query()->where('class_group_id', $group->id)->whereDate('session_date', $data['session_date'])
                ->whereTime('start_time', $data['start_time'])->valueOrFail('id');
        }
        $session = ClassSession::query()->with('classGroup.dojo')->findOrFail($data['session_id']);
        $this->attendance->save($association, $session, $user, [[
            'student_id' => $data['student_id'], 'status' => $data['status'], 'notes' => $data['notes'],
        ]], false);

        return AttendanceRecord::query()->where('class_session_id', $session->id)->where('student_id', $data['student_id'])->firstOrFail();
    }

    private function recount(ImportBatch $batch): void
    {
        $counts = $batch->rows()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $batch->update([
            'total_rows' => $counts->sum(), 'ready_rows' => $counts['ready'] ?? 0, 'review_rows' => $counts['review'] ?? 0,
            'invalid_rows' => $counts['invalid'] ?? 0, 'processed_rows' => ($counts['processed'] ?? 0) + ($counts['already_processed'] ?? 0),
            'failed_rows' => $counts['failed'] ?? 0,
        ]);
        $batch->refresh();
    }

    private function namedArt(Association $association, mixed $name): ?MartialArt
    {
        return MartialArt::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('association_id')->orWhere('association_id', $association->id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $name))])->first();
    }

    private function namedRank(MartialArt $art, mixed $name): ?Rank
    {
        $value = mb_strtolower(trim((string) $name));
        if ($value === '') {
            return null;
        }

        return $art->ranks()->where('status', 'active')->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$value])->orWhereRaw('LOWER(display_name) = ?', [$value]))->first();
    }

    private function namedGroup(Association $association, Dojo $dojo, mixed $name): ?ClassGroup
    {
        $value = mb_strtolower(trim((string) $name));
        if ($value === '') {
            return null;
        }

        return ClassGroup::query()->where('association_id', $association->id)->where('dojo_id', $dojo->id)->where('status', 'active')->whereRaw('LOWER(name) = ?', [$value])->first();
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

    private function time(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (is_numeric($text)) {
            $seconds = (int) round(((float) $text - floor((float) $text)) * 86400);

            return sprintf('%02d:%02d:00', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                $time = Carbon::createFromFormat('!'.$format, $text);
                if ($time && $time->format($format) === $text) {
                    return $time->format('H:i:s');
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function attendanceStatus(mixed $value): ?string
    {
        return match ($this->plain($value)) {
            'presente' => 'present', 'falta', 'ausente' => 'absent', 'falta justificada', 'justificada' => 'excused_absence',
            'reposicao' => 'makeup', 'experimental' => 'trial', default => null,
        };
    }

    private function relationship(mixed $value): string
    {
        return match ($this->plain($value)) {
            'mae' => 'mother', 'pai' => 'father', 'avo', 'avoa' => 'grandparent', 'tutor' => 'legal_guardian', default => 'other',
        };
    }

    private function yes(mixed $value): bool
    {
        return in_array($this->plain($value), ['sim', 's', '1', 'yes', 'true'], true);
    }

    private function plain(mixed $value): string
    {
        return mb_strtolower(Str::ascii(trim((string) $value)));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableLower(mixed $value): ?string
    {
        $value = $this->nullable($value);

        return $value === null ? null : mb_strtolower($value);
    }

    /** @return array{string, ?string, array<string, mixed>} */
    private function rowResult(array $errors, array $review, array $normalized): array
    {
        if ($errors !== []) {
            return ['invalid', implode(' ', array_unique($errors)), $normalized];
        }
        if ($review !== []) {
            return ['review', implode(' ', array_unique($review)), $normalized];
        }

        return ['ready', 'Pronta para importar.', $normalized];
    }
}
