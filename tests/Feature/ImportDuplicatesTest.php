<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\AuditLog;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'nome_completo;cpf;rg;data_nascimento;celular;data_entrada;modalidade;turma';

    public function test_reimport_finds_students_by_cpf_rg_and_name_and_applies_each_choice(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context();
        $morning = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Manhã', 'start_date' => '2026-01-01', 'status' => 'active']);
        $afternoon = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Tarde', 'start_date' => '2026-01-01', 'status' => 'active']);

        $first = $this->import($association, $dojo, $user, 'primeira.csv', [
            'Ana Martins;52998224725;;10/01/2000;11988880000;01/10/2026;Judô;Manhã',
            'Bruno Lima;;12.345.678-9;;;01/10/2026;Judô;',
            'Carla Lima;;;07/10/2014;;01/10/2026;Judô;Manhã',
            'Davi Souza;;;;;01/10/2026;Judô;',
            'Eva Reis;;;01/01/2010;;01/10/2026;Judô;',
        ]);
        $this->post(route('associations.imports.process', [$association, $first]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('students', 5);

        $second = $this->import($association, $dojo, $user, 'segunda.csv', [
            'Ana Martins Souza;529.982.247-25;;;11977771111;28/10/2026;Judô;Tarde',
            'Bruno Lima;;123456789;;11955554444;28/10/2026;Judô;',
            'carla lima;;;;11966662222;28/10/2026;Judô;',
            'Davi Souza;;;01/01/2010;;28/10/2026;Judô;',
            'Eva Reis;;;02/02/2011;;28/10/2026;Judô;',
            'Aluno Novo;;;;;28/10/2026;Judô;',
        ]);
        $this->assertSame(2, $second->ready_rows);
        $this->assertSame(4, ImportRow::query()->where('import_batch_id', $second->id)->where('status', 'duplicate')->count());

        $rows = ImportRow::query()->where('import_batch_id', $second->id)->get()->keyBy('row_number');
        $this->get(route('associations.imports.show', [$association, $second]))->assertOk()
            ->assertSee('4 aluno(s) já existem no JUDOMA')->assertSee('data-check-all="update"', false)
            ->assertSeeInOrder(['Linha 2 · Ana Martins Souza', 'Encontrado pelo CPF', 'Nome', 'Ana Martins', 'Ana Martins Souza', 'Celular', '(11) 98888-0000', '(11) 97777-1111', 'matricular em Tarde'])
            ->assertSeeInOrder(['Linha 3 · Bruno Lima', 'Encontrado pelo RG', 'Celular'])->assertDontSee('123456789')->assertDontSee('<del>Carla Lima</del>', false)
            ->assertSeeInOrder(['Linha 4 · carla lima', 'Mesmo nome', '07/10/2014 no JUDOMA, vazio na planilha (mantém a data)', 'Cadastrar como novo aluno'])
            ->assertSee('Já cadastrados');

        $this->post(route('associations.imports.process', [$association, $second]), ['acoes' => [$rows[2]->id => 'new']])->assertSessionHasErrors("acoes.{$rows[2]->id}");
        $this->assertDatabaseCount('students', 5);

        $this->post(route('associations.imports.process', [$association, $second]), ['acoes' => [
            $rows[2]->id => 'update', $rows[4]->id => 'new', $rows[5]->id => 'update',
        ]])->assertSessionHasNoErrors();

        $ana = Person::query()->where('cpf', '52998224725')->firstOrFail();
        $this->assertSame(['Ana Martins Souza', '11977771111', '2000-01-10'], [$ana->full_name, $ana->mobile, $ana->birth_date->toDateString()]);
        $anaStudent = Student::query()->where('person_id', $ana->id)->firstOrFail();
        $this->assertEqualsCanonicalizing([$morning->id, $afternoon->id], ClassEnrollment::query()->where('student_id', $anaStudent->id)->where('status', 'active')->pluck('class_group_id')->all());
        $this->assertStringContainsString('Matriculado em Tarde.', $rows[2]->fresh()->message);
        $this->assertSame(1, AuditLog::query()->where('event_type', 'person.updated')->where('entity_id', $ana->id)->count());

        $this->assertSame('ignored', $rows[3]->fresh()->status);
        $this->assertNull(Person::query()->where('full_name', 'Bruno Lima')->value('mobile'));
        $this->assertSame(2, Person::query()->whereRaw('LOWER(full_name) = ?', ['carla lima'])->count());
        $this->assertSame('2010-01-01', Person::query()->where('full_name', 'Davi Souza')->first()->birth_date->toDateString());
        $this->assertSame(2, Person::query()->where('full_name', 'Eva Reis')->count());
        $this->assertDatabaseCount('students', 8);
        $this->assertSame('completed', $second->fresh()->status);

        $this->post(route('associations.imports.process', [$association, $second]), ['acoes' => [$rows[3]->id => 'update']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('students', 8);
        $this->assertSame('ignored', $rows[3]->fresh()->status);
    }

    public function test_update_keeps_data_when_class_enrollment_is_not_possible(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context();
        $this->import($association, $dojo, $user, 'primeira.csv', ['Aluno Antigo;;;;;01/10/2026;Judô;']);
        $this->post(route('associations.imports.process', [$association, ImportBatch::query()->firstOrFail()]));
        Student::query()->firstOrFail()->update(['status' => 'inactive']);
        ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Noite', 'start_date' => '2026-01-01', 'status' => 'active']);

        $batch = $this->import($association, $dojo, $user, 'segunda.csv', ['Aluno Antigo;;;;11944443333;01/10/2026;Judô;Noite']);
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('duplicate', $row->status);
        $this->post(route('associations.imports.process', [$association, $batch]))->assertSessionHasNoErrors();
        $this->assertSame('ignored', $row->fresh()->status);
        $this->assertNull(Person::query()->firstOrFail()->mobile);

        $row->refresh()->update(['status' => 'duplicate']);
        $batch->update(['status' => 'processing']);
        $this->post(route('associations.imports.process', [$association, $batch]), ['acoes' => [$row->id => 'update']])->assertSessionHasNoErrors();
        $this->assertSame('11944443333', Person::query()->firstOrFail()->mobile);
        $this->assertSame('processed', $row->fresh()->status);
        $this->assertStringContainsString('Não matriculado em Noite: o aluno não está ativo.', $row->fresh()->message);
        $this->assertDatabaseCount('class_enrollments', 0);
    }

    private function import(Association $association, Dojo $dojo, User $user, string $name, array $lines): ImportBatch
    {
        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'students',
            'file' => UploadedFile::fake()->createWithContent($name, implode("\n", [self::HEADER, ...$lines])),
        ])->assertSessionHasNoErrors();

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    private function context(): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'requires_minor_guardian' => false]);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'A', 'name' => 'Dojo A']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Importador', 'code' => 'import_dup', 'scope_type' => 'association']);
        $role->permissions()->sync(collect(['import.manage', 'student.manage'])->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $dojo, $art, $user];
    }
}
