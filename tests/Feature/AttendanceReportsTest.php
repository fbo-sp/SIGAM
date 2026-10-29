<?php

namespace Tests\Feature;

use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\ClassSessionStaff;
use App\Models\Dojo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AttendanceReportsTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_and_detail_count_only_closed_rolls_within_each_enrollment(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->grant($anaUser, $this->role('teacher', ['report.export']));
        [, $bruno] = $this->teacher('Bruno Auxiliar');
        $group = $this->group($ana);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $bruno->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01']);
        [$a1, $a2, $a3] = [$this->student('A-1'), $this->student('A-2'), $this->student('A-3')];
        $this->enroll($group, $a1);
        $this->enroll($group, $a2);
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $a3->id, 'start_date' => '2026-10-10', 'status' => 'active']);

        $session = fn (string $date, string $state, string $status = 'planned') => ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date,
            'start_time' => '18:00', 'end_time' => '19:00', 'status' => $status, 'attendance_state' => $state]);
        $mark = fn (ClassSession $s, $student, string $status) => $s->records()->create(['student_id' => $student->id, 'participation_type' => 'regular', 'status' => $status, 'recorded_at' => now(), 'recorded_by_user_id' => $anaUser->id]);
        $old = $session('2026-10-05', 'closed');
        $mark($old, $a1, 'present');
        $mark($old, $a2, 'absent');
        $taught = $session('2026-10-12', 'closed');
        foreach ([[$a1, 'present'], [$a2, 'makeup'], [$a3, 'absent']] as [$student, $status]) {
            $mark($taught, $student, $status);
        }
        ClassSessionStaff::query()->create(['class_session_id' => $taught->id, 'teacher_profile_id' => $bruno->id, 'role' => 'lead']);
        ClassSessionStaff::query()->create(['class_session_id' => $taught->id, 'teacher_profile_id' => $ana->id, 'role' => 'assistant']);
        $session('2026-10-19', 'open');
        $session('2026-10-26', 'open', 'cancelled');
        $session('2026-10-30', 'open');
        $other = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul'])->id,
            'martial_art_id' => $this->art->id, 'name' => 'Turma Sul', 'main_teacher_id' => $bruno->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        $this->enroll($other, $this->student('S-1'));
        $mark(ClassSession::query()->create(['class_group_id' => $other->id, 'session_date' => '2026-10-07', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'closed']), ClassEnrollment::query()->where('class_group_id', $other->id)->first()->student, 'present');

        $period = ['de' => '2026-10-01', 'ate' => '2026-10-31'];
        $manager = $this->associationUser(['report.export']);
        $this->actingAs($manager)->get(route('associations.reports.index', [$this->association, 'previa' => 'frequencia', 'dojo_id' => $this->dojo->id] + $period))->assertOk()
            ->assertSee('Frequência')->assertSee('2 aula(s) com chamada finalizada')->assertSee('1 com chamada aberta')->assertSee('3 aluno(s)')->assertSee('8 linha(s) na detalhada');

        $summary = $this->book($this->get(route('associations.reports.attendance', [$this->association, 'kind' => 'resumida', 'dojo_id' => $this->dojo->id] + $period))->assertOk());
        $this->assertEquals(['A-1', 'Turma A', 2, 2, 0, 1, 1], $this->pick($summary['Por turma'][6], ['A', 'D', 'E', 'F', 'G', 'H', 'I']));
        $this->assertEquals(['A-2', 2, 1, 1, 0.5], $this->pick($summary['Por turma'][7], ['A', 'E', 'F', 'G', 'H']));
        $this->assertEquals(['A-3', 1, 0, 1, 0, 1], $this->pick($summary['Por turma'][8], ['A', 'E', 'F', 'G', 'H', 'I']));
        $this->assertCount(3, array_slice($summary['Por turma'], 1));
        $this->assertEquals(['A-3', 1, 1, 0, 1, 0], $this->pick($summary['Por aluno'][8], ['A', 'C', 'D', 'E', 'F', 'G']));

        $detail = $this->book($this->get(route('associations.reports.attendance', [$this->association, 'kind' => 'detalhada'] + $period))->assertOk())['Aula a aula'];
        $rows = array_slice($detail, 1);
        $this->assertCount(9, $rows);
        $this->assertSame(['A-1', 'Turma A', 'Seg', '18:00', 'Professora Ana (previsto)', null, 'SIM', 'Finalizada'], $this->pick($rows[0], ['A', 'C', 'E', 'F', 'G', 'H', 'I', 'J']));
        $this->assertSame(['Bruno Auxiliar', 'Professora Ana', 'SIM'], $this->pick($rows[1], ['G', 'H', 'I']));
        $this->assertSame(['—', 'Aberta'], $this->pick($rows[2], ['I', 'J']));
        $this->assertSame(['A-3', 'NÃO'], $this->pick($rows[6], ['A', 'I']));
        $this->assertSame('S-1', $rows[8]['A']);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'report.exported']);

        // Professor: só a turma dele; período acima de 366 dias é recusado.
        $teacherRows = array_slice($this->book($this->actingAs($anaUser)->get(route('teacher.reports.attendance', ['kind' => 'detalhada'] + $period))->assertOk())['Aula a aula'], 1);
        $this->assertSame(['A-1', 'A-1', 'A-1', 'A-2', 'A-2', 'A-2', 'A-3', 'A-3'], array_column($teacherRows, 'A'));
        $this->get(route('teacher.reports.attendance', ['kind' => 'resumida', 'class_group_id' => $other->id] + $period))->assertSessionHasErrors('class_group_id');
        $this->get(route('teacher.reports.attendance', ['kind' => 'resumida', 'de' => '2025-01-01', 'ate' => '2026-10-31']))->assertSessionHasErrors('ate');
        $dojoManager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($dojoManager, $this->role('dojo', ['dojo.access', 'report.export']));
        $this->assertCount(3, array_slice($this->book($this->actingAs($dojoManager)->get(route('operation.reports.attendance', [$this->dojo, 'kind' => 'resumida'] + $period))->assertOk())['Por turma'], 1));
        $this->actingAs($anaUser)->get(route('teacher.reports.index'))->assertOk()->assertSee('Baixar detalhada')->assertSee('value="2026-10-01"', false)->assertSee('value="2026-10-31"', false);
    }

    /** @return array<string, array<int, array<string, mixed>>> abas da planilha, linhas a partir do cabeçalho */
    private function book(TestResponse $response): array
    {
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'freq').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheets = [];
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, true);
            $sheets[$sheet->getTitle()] = array_filter($rows, fn ($row, $index) => $index >= 5, ARRAY_FILTER_USE_BOTH);
        }
        unlink($path);

        return $sheets;
    }

    private function pick(array $row, array $columns): array
    {
        return array_map(fn (string $column) => $row[$column], $columns);
    }
}
