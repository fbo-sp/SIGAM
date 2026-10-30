<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuardianImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'matricula_aluno;nome_aluno;nascimento_aluno;nome_responsavel;cpf;celular;email;parentesco;legal;financeiro;principal;avisos';

    public function test_preview_reuses_people_skips_existing_links_and_processes_with_the_guardian_rules(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-30 08:00');
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $other = Dojo::query()->create(['association_id' => $association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Importador', 'code' => 'import_guardians', 'scope_type' => 'association']);
        $role->permissions()->sync(collect(['import.manage', 'student.manage'])->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));
        $user->roles()->attach($role, ['association_id' => $association->id]);

        $clara = $this->student($association, $dojo, 'ALU-1', 'Clara Ribeiro', '2016-05-01');
        $pedro = $this->student($association, $dojo, 'ALU-2', 'Pedro Ribeiro', '2018-02-03');
        $davi = $this->student($association, $dojo, 'ALU-3', 'Davi Alves', '2015-01-01');
        $this->student($association, $other, 'ALU-4', 'Aluno do Sul', '2015-01-01');
        $carlos = $this->person($association, 'Carlos Lima', '52998224725');
        $rita = $this->person($association, 'Rita Alves');
        StudentGuardian::query()->create(['student_id' => $davi->id, 'guardian_person_id' => $rita->id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'start_date' => '2026-01-01']);
        $rita->update(['mobile' => '11933332222']);

        $this->actingAs($user)->post(route('associations.imports.store', $association), [
            'dojo_id' => $dojo->id, 'import_type' => 'guardians',
            'file' => UploadedFile::fake()->createWithContent('responsaveis.csv', implode("\n", [self::HEADER,
                'ALU-1;;;Marta Ribeiro;;11988880000;marta@example.com;mãe;sim;sim;sim;',
                ';pedro ribeiro;03/02/2018;Marta Ribeiro;;(11) 98888-0000;;Mãe;sim;não;sim;sim',
                'ALU-2;;;Carlos Lima;529.982.247-25;;;pai;não;sim;não;não',
                'ALU-3;;;Rita Alves;;11933332222;;mãe;sim;;;',
                ';Joao Nunes;;Paulo Nunes;;;;pai;;;;',
                'ALU-4;;;Pai do Sul;;;;pai;;;;',
                'ALU-1;;;Marta Ribeiro;;11988880000;;mãe;;;;',
            ])),
        ])->assertSessionHasNoErrors();
        $batch = ImportBatch::query()->firstOrFail();
        $rows = ImportRow::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get()->keyBy('row_number');
        $this->assertSame(['ready', 'ready', 'review', 'already_processed', 'invalid', 'invalid', 'invalid'], $rows->pluck('status')->values()->all());
        $this->assertSame(2, $rows[3]->normalized_payload['same_as_row']);
        $this->assertStringContainsString('CPF já cadastrado (Carlos Lima)', $rows[4]->message);
        $this->assertStringContainsString('Aluno não encontrado neste dojo', $rows[6]->message);
        $this->assertStringContainsString('matrícula do aluno não foi encontrada neste dojo', $rows[7]->message);
        $this->assertStringContainsString('repetido no arquivo (linha 2)', $rows[8]->message);

        $this->get(route('associations.imports.show', [$association, $batch]))->assertOk()->assertSee('Responsáveis · Dojo Norte')
            ->assertSee('Marta Ribeiro → Clara Ribeiro')->assertSee('Rita Alves → Davi Alves')->assertSee('Já existe');

        $this->post(route('associations.imports.process', [$association, $batch]), ['confirm_reviews' => '1'])->assertSessionHasNoErrors();
        $marta = Person::query()->where('full_name', 'Marta Ribeiro')->sole();
        $this->assertSame(['11988880000', 'marta@example.com'], [$marta->mobile, $marta->email]);
        $this->assertEqualsCanonicalizing([$clara->id, $pedro->id], StudentGuardian::query()->where('guardian_person_id', $marta->id)->pluck('student_id')->all());
        $link = StudentGuardian::query()->where('student_id', $pedro->id)->where('guardian_person_id', $carlos->id)->sole();
        $this->assertSame(['father', false, true, false], [$link->relationship_type, $link->is_legal_guardian, $link->is_financial_guardian, $link->can_receive_notifications]);
        $this->assertTrue(StudentGuardian::query()->where('student_id', $clara->id)->sole()->can_receive_notifications);
        $this->assertSame(1, StudentGuardian::query()->where('student_id', $davi->id)->count());
        $this->assertSame('completed', $batch->fresh()->status);
    }

    private function student(Association $association, Dojo $dojo, string $registration, string $name, string $birth): Student
    {
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $this->person($association, $name, null, $birth)->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);

        return $student;
    }

    private function person(Association $association, string $name, ?string $cpf = null, ?string $birth = '1985-01-01'): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'cpf' => $cpf, 'birth_date' => $birth]);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);

        return $person;
    }
}
