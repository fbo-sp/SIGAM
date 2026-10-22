<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistedImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_xlsx_template_can_be_read_without_formulas(): void
    {
        $reader = app(SpreadsheetReader::class);

        $this->assertSame('Maria de Souza', $reader->read(resource_path('imports/modelo_importacao_judoma.xlsx'), 'xlsx', 'Alunos')[0]['values']['nome_completo']);
        $this->assertSame('ALU-000001-000001', $reader->read(resource_path('imports/modelo_importacao_judoma.xlsx'), 'xlsx', 'Presenças')[0]['values']['matricula_aluno']);
    }

    public function test_manager_previews_and_processes_student_file_idempotently(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context(['import.manage', 'student.manage']);
        $csv = implode("\n", [
            'nome_completo;cpf;data_nascimento;email;celular;data_entrada;modalidade;graduacao;turma;ativar;nome_responsavel;cpf_responsavel;parentesco;responsavel_legal;observacoes',
            'Aluno Importado;;10/01/2000;aluno@teste.invalid;;01/10/2026;Judô;;;;;;;Cadastro inicial',
        ]);

        $response = $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'students', 'file' => UploadedFile::fake()->createWithContent('alunos.csv', $csv),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('associations.imports.show', [$association, $batch]))->assertSessionHasNoErrors();
        $this->assertSame(1, $batch->ready_rows);
        Storage::disk('local')->assertExists($batch->storage_path);

        $this->post(route('associations.imports.process', [$association, $batch]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['association_id' => $association->id, 'primary_dojo_id' => $dojo->id]);
        $this->assertDatabaseHas('import_rows', ['import_batch_id' => $batch->id, 'status' => 'processed']);

        $this->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'students', 'file' => UploadedFile::fake()->createWithContent('repetido.csv', $csv),
        ])->assertRedirect(route('associations.imports.show', [$association, $batch]));
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_existing_cpf_requires_explicit_review_confirmation_and_is_reused(): void
    {
        Storage::fake('local');
        [$association, $dojo, , $user] = $this->context(['import.manage', 'student.manage']);
        $person = Person::query()->create(['full_name' => 'Pessoa Existente', 'cpf' => '52998224725', 'birth_date' => '2000-01-10']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => today(), 'status' => 'active']);
        $csv = "nome_completo;cpf;data_nascimento;data_entrada;modalidade\nPessoa Existente;52998224725;10/01/2000;01/10/2026;Judô";
        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'students', 'file' => UploadedFile::fake()->createWithContent('alunos.csv', $csv),
        ]);
        $batch = ImportBatch::query()->firstOrFail();

        $this->post(route('associations.imports.process', [$association, $batch]))->assertSessionHasErrors('confirm_reviews');
        $this->post(route('associations.imports.process', [$association, $batch]), ['confirm_reviews' => '1'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseHas('students', ['person_id' => $person->id, 'association_id' => $association->id]);
    }

    public function test_attendance_file_updates_open_session_and_replay_does_not_duplicate(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context(['import.manage', 'attendance.manage']);
        $group = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Adultos', 'start_date' => '2026-01-01', 'status' => 'active']);
        $person = Person::query()->create(['full_name' => 'Aluno', 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'student', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'ALU-1', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-08', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $csv = "matricula_aluno;turma;data_aula;horario_inicio;situacao;observacoes\nALU-1;Adultos;08/10/2026;18:00;presente;Importada";
        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'attendance', 'file' => UploadedFile::fake()->createWithContent('presencas.csv', $csv),
        ]);
        $batch = ImportBatch::query()->firstOrFail();

        $this->post(route('associations.imports.process', [$association, $batch]))->assertSessionHasNoErrors();
        $this->post(route('associations.imports.process', [$association, $batch]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('attendance', 1);
        $this->assertDatabaseHas('attendance', ['class_session_id' => $session->id, 'student_id' => $student->id, 'status' => 'present']);
    }

    public function test_attendance_import_creates_missing_past_session_from_class_schedule(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context(['import.manage', 'attendance.manage']);
        $group = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Adultos', 'start_date' => '2026-01-01', 'status' => 'active']);
        ClassSchedule::query()->create(['class_group_id' => $group->id, 'weekday' => 4, 'start_time' => '18:00', 'end_time' => '19:30', 'valid_from' => '2026-01-01', 'status' => 'active']);
        $person = Person::query()->create(['full_name' => 'Aluno', 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'student', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'ALU-1', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        $csv = implode("\n", [
            'matricula_aluno;turma;data_aula;horario_inicio;situacao',
            'ALU-1;Adultos;08/10/2026;18:00;presente',
            'ALU-1;Adultos;10/12/2025;18:00;presente',
            'ALU-1;Adultos;08/10/2026;07:00;presente',
            'ALU-1;Adultos;'.today()->addWeek()->format('d/m/Y').';18:00;presente',
        ]);

        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'attendance', 'file' => UploadedFile::fake()->createWithContent('presencas.csv', $csv),
        ]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(['invalid' => 3, 'review' => 1], ImportRow::query()->pluck('status')->countBy()->sortKeys()->all());
        $this->assertDatabaseCount('class_sessions', 0);
        $this->post(route('associations.imports.process', [$association, $batch]), ['confirm_reviews' => '1'])->assertSessionHasNoErrors();
        $this->post(route('associations.imports.process', [$association, $batch]), ['confirm_reviews' => '1'])->assertSessionHasNoErrors();

        $session = ClassSession::query()->sole();
        $this->assertSame('2026-10-08', $session->session_date->toDateString());
        $this->assertSame('19:30', substr($session->end_time, 0, 5));
        $this->assertDatabaseCount('attendance', 1);
        $this->assertDatabaseHas('attendance', ['class_session_id' => $session->id, 'student_id' => $student->id, 'status' => 'present']);
    }

    public function test_local_operator_cannot_import_into_another_dojo_of_same_association(): void
    {
        Storage::fake('local');
        [$association, $dojo] = $this->context([]);
        $other = Dojo::query()->create(['association_id' => $association->id, 'code' => 'B', 'name' => 'Outro dojo']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'dojo', ['dojo.access', 'import.manage', 'student.manage']);
        DojoRoleAssignment::query()->create([
            'association_id' => $association->id, 'dojo_id' => $dojo->id, 'user_id' => $user->id,
            'role_id' => $role->id, 'start_date' => today(), 'created_by_user_id' => $user->id,
        ]);
        $csv = "nome_completo;data_nascimento;data_entrada;modalidade\nAluno;01/01/2000;01/10/2026;Judô";

        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $other->id, 'import_type' => 'students', 'file' => UploadedFile::fake()->createWithContent('alunos.csv', $csv),
        ])->assertForbidden();
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_attendance_import_does_not_change_closed_session(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context(['import.manage', 'attendance.manage']);
        $group = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Fechada', 'start_date' => '2026-01-01', 'status' => 'active']);
        $person = Person::query()->create(['full_name' => 'Aluno', 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'student', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'ALU-X', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-08', 'start_time' => '19:00', 'end_time' => '20:00', 'status' => 'held', 'attendance_state' => 'closed']);
        $csv = "matricula_aluno;turma;data_aula;horario_inicio;situacao\nALU-X;Fechada;08/10/2026;19:00;presente";

        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'attendance', 'file' => UploadedFile::fake()->createWithContent('fechada.csv', $csv),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->invalid_rows);
        $this->post(route('associations.imports.process', [$association, $batch]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_retention_removes_private_file_and_payload_but_preserves_history(): void
    {
        Storage::fake('local');
        [$association, $dojo, , $user] = $this->context(['import.manage', 'student.manage']);
        Storage::disk('local')->put('imports/old.csv', 'dados privados');
        $batch = ImportBatch::query()->create([
            'association_id' => $association->id, 'dojo_id' => $dojo->id, 'created_by_user_id' => $user->id,
            'import_type' => 'students', 'original_name' => 'old.csv', 'stored_name' => 'old.csv', 'storage_path' => 'imports/old.csv',
            'mime_type' => 'text/csv', 'file_size' => 14, 'file_hash' => str_repeat('a', 64), 'status' => 'reviewing', 'total_rows' => 1,
        ]);
        $batch->forceFill(['created_at' => now()->subDays(31)])->save();
        ImportRow::query()->create([
            'import_batch_id' => $batch->id, 'row_number' => 2, 'row_hash' => str_repeat('b', 64),
            'raw_payload' => ['cpf' => '52998224725'], 'normalized_payload' => ['person' => ['cpf' => '52998224725']],
            'status' => 'ready', 'initial_status' => 'ready', 'message' => 'Pronta.',
        ]);

        $this->artisan('sigam:imports:purge-files --days=30')->assertSuccessful();

        Storage::disk('local')->assertMissing('imports/old.csv');
        $this->assertSame('expired', $batch->fresh()->status);
        $this->assertSame([], $batch->rows()->firstOrFail()->raw_payload);
        $this->assertNull($batch->rows()->firstOrFail()->normalized_payload);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('import_rows', 1);
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'A', 'name' => 'Dojo A']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        if ($codes === []) {
            return [$association, $dojo, $art, null];
        }
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'association', $codes);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $dojo, $art, $user];
    }

    private function role(Association $association, string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Importador', 'code' => 'import_'.Str::random(8), 'scope_type' => $scope]);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id);
        $role->permissions()->sync($permissions);

        return $role;
    }
}
