<?php

namespace Tests\Feature;

use App\Models\ClassEnrollment;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\ClassSessionStaff;
use App\Models\ClassSessionTechnique;
use App\Models\User;
use App\Support\Export\PdfReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AttendanceListPdfTest extends TestCase
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

    /** Troca o PDF pelo HTML da mesma view, para conferir o conteúdo. */
    private function htmlInsteadOfPdf(): void
    {
        $this->app->instance(PdfReport::class, new class extends PdfReport
        {
            public function download(string $view, array $data, string $filename, string $footer, bool $landscape = false): Response
            {
                return response(view($view, $data)->render().'<footer>'.$filename.' · '.$footer.($landscape ? ' · paisagem' : '').'</footer>');
            }
        });
    }

    private function makeSession(int $groupId, string $date, string $state): ClassSession
    {
        return ClassSession::query()->create(['class_group_id' => $groupId, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => $state]);
    }

    public function test_session_list_is_closed_with_answers_or_blank_and_respects_access(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->grant($anaUser, $this->role('teacher', ['report.export']));
        [$brunoUser, $bruno] = $this->teacher('Bruno Auxiliar');
        $group = $this->group($ana);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $bruno->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01']);
        [$a1, $a2] = [$this->student('A-1'), $this->student('A-2')];
        $a1->person->update(['full_name' => 'Zeca Souza']);
        $this->enroll($group, $a1);
        $this->enroll($group, $a2);
        $closed = $this->makeSession($group->id, '2026-10-12', 'closed');
        $closed->update(['closed_at' => '2026-10-12 19:05:00', 'closed_by_user_id' => $anaUser->id]);
        $closed->records()->create(['student_id' => $a1->id, 'participation_type' => 'regular', 'status' => 'present', 'recorded_at' => now(), 'recorded_by_user_id' => $anaUser->id]);
        $closed->records()->create(['student_id' => $a2->id, 'participation_type' => 'regular', 'status' => 'absent', 'recorded_at' => now(), 'recorded_by_user_id' => $anaUser->id]);
        ClassSessionStaff::query()->create(['class_session_id' => $closed->id, 'teacher_profile_id' => $ana->id, 'role' => 'lead']);
        ClassSessionStaff::query()->create(['class_session_id' => $closed->id, 'teacher_profile_id' => $bruno->id, 'role' => 'assistant']);
        $item = $this->item('Ukemi', 'practical');
        ClassSessionTechnique::query()->create(['association_id' => $this->association->id, 'class_session_id' => $closed->id, 'technique_item_id' => $this->item('O-goshi', 'practical', $item)->id, 'marked_by_user_id' => $anaUser->id]);
        $future = $this->makeSession($group->id, '2026-11-02', 'open');

        $this->htmlInsteadOfPdf();
        // Professor com report.export vê o botão.
        $this->actingAs($anaUser)->get(route('operation.session', [$this->dojo, $closed]))->assertOk()->assertSee('Baixar lista de presença (PDF)');
        $this->get(route('operation.session', [$this->dojo, $future]))->assertSee('Imprimir lista em branco (PDF)');
        $this->get(route('operation.session.list', [$this->dojo, $closed]))->assertOk()
            ->assertSee('Chamada finalizada')->assertSee('seg 12/10/2026 · 18:00')->assertSee('Professora Ana')->assertSee('Bruno Auxiliar')->assertSee('O-goshi')
            ->assertSee('12/10/2026 19:05 por')->assertSeeInOrder(['A-2', 'NÃO', 'Zeca Souza', 'SIM'])->assertSee('Presentes: 1')->assertSee('Ausentes: 1')
            ->assertSee('lista-presenca-turma-a-2026-10-12.pdf');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'report.exported']);
        $this->get(route('operation.session.list', [$this->dojo, $future]))->assertOk()
            ->assertSee('Lista de presença para preencher')->assertSee('Professor previsto')->assertSee('Visitante: ')->assertSee('Assinatura do professor')->assertDontSee('Presentes:');

        // Sem report.export não há botão nem download; a associação baixa com a permissão.
        $this->actingAs($brunoUser)->get(route('operation.session', [$this->dojo, $closed]))->assertOk()->assertDontSee('lista de presença (PDF)');
        $this->get(route('operation.session.list', [$this->dojo, $closed]))->assertNotFound();
        $manager = $this->associationUser(['session.view', 'report.export']);
        $this->actingAs($manager)->get(route('associations.attendance.show', [$this->association, $closed]))->assertSee('Baixar lista de presença (PDF)');
        $this->get(route('associations.attendance.list', [$this->association, $closed]))->assertOk()->assertSee('Chamada finalizada');
        $this->actingAs(User::factory()->create(['association_id' => $this->association->id]))->get(route('associations.attendance.list', [$this->association, $closed]))->assertForbidden();
    }

    public function test_real_pdf_is_generated(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->grant($anaUser, $this->role('teacher', ['report.export']));
        $group = $this->group($ana);
        $this->enroll($group, $this->student('A-1'));
        $session = $this->makeSession($group->id, '2026-10-12', 'open');

        $pdf = $this->actingAs($anaUser)->get(route('operation.session.list', [$this->dojo, $session]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('lista-presenca-turma-a-2026-10-12.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $grid = $this->get(route('teacher.reports.grid', ['grade_turma' => $group->id, 'mes' => '2026-10']))->assertOk();
        $this->assertStringStartsWith('%PDF', $grid->getContent());
    }

    public function test_month_grid_lists_sessions_and_students_of_the_month(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->grant($anaUser, $this->role('teacher', ['report.export']));
        $group = $this->group($ana);
        $a1 = $this->student('A-1');
        $this->enroll($group, $a1);
        ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $this->student('A-2')->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'ended']);
        foreach (['2026-10-05', '2026-10-07', '2026-10-12'] as $date) {
            $this->makeSession($group->id, $date, 'open');
        }
        $this->makeSession($group->id, '2026-10-14', 'open')->update(['status' => 'cancelled']);

        $this->htmlInsteadOfPdf();
        $this->actingAs($anaUser)->get(route('teacher.reports.index'))->assertOk()->assertSee('Baixar grade do mês');
        $this->get(route('teacher.reports.grid', ['grade_turma' => $group->id, 'mes' => '2026-10']))->assertOk()
            ->assertSee('Outubro de 2026')->assertSeeInOrder(['05/10', '07/10', '12/10'])->assertDontSee('14/10')->assertSee('seg e qua · 18:00')
            ->assertSee('A-1')->assertDontSee('A-2')->assertSee('Professora Ana')->assertSee('paisagem');
        $this->get(route('teacher.reports.grid', ['grade_turma' => $group->id, 'mes' => '2026-12']))->assertSessionHasErrors('mes');
        $this->get(route('teacher.reports.grid', ['grade_turma' => 999, 'mes' => '2026-10']))->assertSessionHasErrors('grade_turma');
    }
}
