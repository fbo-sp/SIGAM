<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_student_list_follows_the_scope_of_each_level(): void
    {
        [$teacherUser, $teacher] = $this->teacher('Professora Ana');
        $this->grant($teacherUser, $this->role('teacher', ['report.export']));
        $group = $this->group($teacher);
        $mine = $this->student('A-1', '2015-03-10');
        $mine->person->update(['full_name' => '=HYPERLINK("x") Aluno', 'cpf' => '52998224725', 'email' => 'a1@teste.invalid', 'mobile' => '11999990001']);
        $guardian = $this->person('Mãe do A-1');
        $guardian->update(['mobile' => '11988887777']);
        StudentGuardian::query()->create(['student_id' => $mine->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'is_primary' => true, 'start_date' => '2026-01-01']);
        $this->enroll($group, $mine);
        ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-14', 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $other = $this->student('A-2');
        $pending = $this->student('A-3');
        $pending->update(['status' => 'pre_registration']);
        $north = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $far = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person('A-4')->id, 'registration_number' => 'A-4', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $north->id, 'status' => 'active']);
        $far->dojoHistory()->create(['dojo_id' => $north->id, 'start_date' => '2026-01-01', 'is_primary' => true]);

        $manager = $this->associationUser(['report.export']);
        $this->actingAs($manager)->get(route('associations.reports.index', [$this->association, 'previa' => 1]))->assertOk()->assertSee('Lista de alunos')->assertSee('4 aluno(s)');
        $sheet = $this->sheet($this->get(route('associations.reports.students', $this->association))->assertOk());
        $this->assertSame('CPF', $sheet[5]['K']);
        $this->assertSame(['A-1', 'A-2', 'A-3', 'A-4'], array_column(array_slice($sheet, 1), 'A'));
        $this->assertSame('=HYPERLINK("x") Aluno', $sheet[6]['B']);
        $this->assertSame('529.982.247-25', $sheet[6]['K']);
        $this->assertSame('Turma A', $sheet[6]['H']);
        $this->assertSame('Mãe do A-1', $sheet[6]['N']);
        $this->assertSame('11988887777', $sheet[6]['O']);
        $this->assertSame('Pré-cadastro', $sheet[8]['I']);
        $this->assertSame(['A-1', 'A-2'], array_column(array_slice($this->sheet($this->get(route('associations.reports.students', [$this->association, 'dojo_id' => $this->dojo->id, 'situacao' => 'so_ativos']))), 1), 'A'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'report.exported', 'association_id' => $this->association->id]);

        $dojoManager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($dojoManager, $this->role('dojo', ['dojo.access', 'student.view', 'report.export']));
        $this->actingAs($dojoManager)->get(route('operation.home', $this->dojo))->assertOk()->assertSee(route('operation.reports.index', $this->dojo), false);
        $this->assertSame(['A-1', 'A-2', 'A-3'], array_column(array_slice($this->sheet($this->get(route('operation.reports.students', $this->dojo))), 1), 'A'));
        $this->get(route('operation.reports.students', [$this->dojo, 'dojo_id' => $north->id]))->assertSessionHasErrors('dojo_id');

        $this->actingAs($teacherUser)->get(route('teacher.reports.index'))->assertOk()->assertSee('CPF e e-mail não entram');
        $teacherSheet = $this->sheet($this->get(route('teacher.reports.students')));
        $this->assertSame(['A-1'], array_column(array_slice($teacherSheet, 1), 'A'));
        $this->assertNotContains('CPF', $teacherSheet[5]);
        $this->assertNotContains('E-mail', $teacherSheet[5]);
        $otherGroup = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma B', 'start_date' => '2026-09-01', 'status' => 'active']);
        $this->get(route('teacher.reports.students', ['class_group_id' => $otherGroup->id]))->assertSessionHasErrors('class_group_id');
        $this->get(route('operation.reports.index', $this->dojo))->assertForbidden();
        $this->get(route('associations.reports.index', $this->association))->assertForbidden();

        [$plainTeacher] = $this->teacher('Professor Sem Relatório');
        $this->actingAs($plainTeacher)->get(route('teacher.reports.index'))->assertForbidden();
    }

    /** @return array<int, array<string, mixed>> */
    private function sheet(TestResponse $response): array
    {
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'rel').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, true);
        unlink($path);

        return array_filter($rows, fn ($row, $index) => $index >= 5, ARRAY_FILTER_USE_BOTH);
    }
}
