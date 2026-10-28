<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\Dojo;
use App\Models\Rank;
use App\Models\StudentGuardian;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Progression\CurriculumCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class TeacherViewTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_dashboard_links_classes_coverage_and_emergency_contact(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        [$brunoUser, $bruno] = $this->teacher('Professor Bruno');
        foreach ([$anaUser, $brunoUser] as $user) {
            $user->roles()->attach($this->role('association', ['dashboard.teacher']), ['association_id' => $this->association->id]);
        }
        $group = $this->group($ana);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $bruno->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01']);
        $white = $this->student('A-1', '2016-05-01');
        $white->person->update(['emergency_contact_name' => 'Maria Avó', 'emergency_contact_phone' => '(11) 98888-7777', 'mobile' => '(11) 95555-4444']);
        $guardian = $this->person('Carlos Pai');
        $guardian->update(['mobile' => '(11) 97777-6666']);
        StudentGuardian::query()->create(['student_id' => $white->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'father', 'is_legal_guardian' => true, 'is_primary' => true, 'start_date' => '2026-01-01']);
        $yellow = $this->student('A-2');
        $yellow->martialArts()->first()->update(['current_rank_id' => $this->yellow->id]);
        $this->enroll($group, $white);
        $this->enroll($group, $yellow);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'closed']);
        ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-20', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        ClassSessionTechnique::query()->create(['association_id' => $this->association->id, 'class_session_id' => $session->id, 'technique_item_id' => $this->items['O-goshi']->id, 'marked_by_user_id' => $anaUser->id]);

        $coverage = app(CurriculumCoverageService::class)->forClass($this->association, $group, today()->subDays(90), today());
        $this->assertSame([5, 1, 20, 'low'], [$coverage['overall']['total'], $coverage['overall']['worked'], $coverage['overall']['percent'], $coverage['overall']['tone']]);
        $this->assertSame(['Faixa amarela', 'Faixa laranja'], $coverage['ranks']->map(fn ($row) => $row['rank']->display_name)->all());
        $this->assertSame([1, 1], $coverage['ranks']->map(fn ($row) => $row['students']->count())->all());
        $this->assertSame(100, $coverage['overall']['groups']->firstWhere('label', 'Nage-waza')['percent']);
        $this->assertSame(1, $coverage['sessions_with_techniques']);
        $this->assertSame(['jul', 'ago', 'set', 'out'], array_column($coverage['monthly']['months'], 'label'));
        $this->assertSame([['label' => 'Nage-waza', 'values' => ['2026-07' => 0, '2026-08' => 0, '2026-09' => 0, '2026-10' => 1]]], $coverage['monthly']['series']);
        $this->assertSame(['2026-10-14'], $coverage['map']['sessions']->map(fn ($session) => $session->session_date->toDateString())->all());
        $this->assertSame([['O-goshi', [true]]], $coverage['map']['rows']->map(fn ($row) => [$row['item']->name, $row['cells']])->all());
        $this->assertSame('medium', CurriculumCoverageService::tone(40));
        $this->assertSame('high', CurriculumCoverageService::tone(70));

        $this->actingAs($anaUser)->get(route('dashboard'))->assertOk()->assertSee('Aulas de hoje')->assertSee('Próximos dias')
            ->assertSee(route('teacher.classes'), false)->assertSee(route('teacher.students'), false)->assertSee(route('teacher.study'), false)->assertDontSee('chamada(s) aberta(s)');
        $this->get(route('teacher.classes'))->assertOk()->assertSee('← Início')->assertSee(route('operation.classes.show', [$this->dojo, $group]), false)
            ->assertSee('2 aluno(s) · principal')->assertSee(route('operation.classes.coverage', [$this->dojo, $group]), false)->assertSee('20%');
        $this->get(route('teacher.students'))->assertOk()->assertSee('← Início')->assertSee('A-1')->assertSee('A-2')->assertSee('href="tel:11955554444"', false)
            ->assertSee('data-modal-open="emergencia-'.$white->id.'"', false)->assertSee('Faltando (0)');
        $this->actingAs($brunoUser)->get(route('teacher.classes'))->assertOk()->assertSee('2 aluno(s) · auxiliar')
            ->assertSee(route('operation.classes.show', [$this->dojo, $group]), false);

        $this->actingAs($anaUser)->get(route('operation.classes.show', [$this->dojo, $group]))->assertOk()
            ->assertSee('← Início')->assertSee('data-modal-open="emergencia-'.$white->id.'"', false)
            ->assertSee('Maria Avó')->assertSee('href="tel:11988887777"', false)
            ->assertSee('Responsável legal')->assertSee('Carlos Pai')->assertSee('href="tel:11977776666"', false)
            ->assertSee('menor de idade')->assertSee('Cobertura do currículo');

        $this->get(route('operation.classes.coverage', [$this->dojo, $group]))->assertOk()
            ->assertSeeInOrder(['Visão geral da turma', '1 de 5 itens já trabalhados', 'Por faixa', 'Rumo a Faixa amarela', 'Rumo a Faixa laranja'])
            ->assertSee('Distribuição por grupo')->assertSee('Nage-waza · out: 1 aula(s)')->assertSee('Mapa aula a aula')->assertSee('14/10: trabalhado')->assertSee('Ainda não trabalhado (4)')->assertSee('Kesa-gatame')->assertSee('← Início');
        $this->get(route('operation.classes.coverage', [$this->dojo, $group, 'de' => '2026-10-15', 'ate' => '2026-10-01']))->assertSessionHasErrors('ate');
        $this->get(route('operation.classes.coverage', [$this->dojo, $group, 'de' => '2026-10-15']))->assertOk()->assertSee('0 de 5 itens já trabalhados');

        [$otherUser, $other] = $this->teacher('Professora Clara');
        $this->actingAs($otherUser)->get(route('operation.classes.coverage', [$this->dojo, $group]))->assertForbidden();
        $plain = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($plain, $this->role('dojo', ['dojo.access', 'student.view']));
        $this->actingAs($plain)->get(route('operation.classes.coverage', [$this->dojo, $group]))->assertForbidden();
        $south = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $this->actingAs($anaUser)->get(route('operation.classes.coverage', [$south, $group]))->assertStatus(403);
    }

    public function test_today_shows_pending_and_today_attendance(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $anaUser->roles()->attach($this->role('association', ['dashboard.teacher']), ['association_id' => $this->association->id]);
        $group = $this->group($ana);
        $absent = $this->student('A-1');
        $this->enroll($group, $absent);
        $old = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-09-10', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $today = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-15', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        foreach (['2026-10-01', '2026-10-06', '2026-10-08'] as $i => $date) {
            $past = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'held', 'attendance_state' => 'closed']);
            AttendanceRecord::query()->create(['class_session_id' => $past->id, 'student_id' => $absent->id, 'status' => 'absent', 'participation_type' => 'regular', 'recorded_at' => $date.' 19:00:00', 'recorded_by_user_id' => $anaUser->id]);
        }

        $this->actingAs($anaUser)->get(route('dashboard'))->assertOk()->assertSee('1 chamada(s) aberta(s) de dias anteriores')
            ->assertSee(route('operation.session', [$this->dojo, $old]), false)->assertSee('Fazer chamada')
            ->assertSee(route('operation.session', [$this->dojo, $today]), false);
        $this->get(route('teacher.students', ['filtro' => 'faltando']))->assertOk()->assertSee('A-1')->assertSee('3 faltas seguidas');
        $this->get(route('teacher.students', ['busca' => 'ninguém']))->assertOk()->assertSee('Nenhum aluno encontrado');

        $plain = User::factory()->create(['association_id' => $this->association->id]);
        $this->actingAs($plain)->get(route('teacher.classes'))->assertForbidden();
    }

    public function test_dojo_manager_who_teaches_gets_today_and_tabs(): void
    {
        $person = $this->person('Gestor Professor');
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $this->association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $manager = User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id, 'dojo_id' => $this->dojo->id]);
        $manager->roles()->attach($this->role('association', ['dashboard.dojo']), ['association_id' => $this->association->id]);
        $this->grant($manager, $this->role('dojo', ['dojo.access', 'class.view', 'session.view', 'attendance.manage', 'student.view', 'progression.report']));
        $group = $this->group($teacher);
        $this->enroll($group, $this->student('A-1'));
        $mine = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => '2026-10-15', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        [, $other] = $this->teacher('Outra professora');
        $otherGroup = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma da outra', 'main_teacher_id' => $other->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        ClassSession::query()->create(['class_group_id' => $otherGroup->id, 'session_date' => '2026-10-15', 'start_time' => '20:00', 'end_time' => '21:00', 'status' => 'planned', 'attendance_state' => 'open']);

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('Painel do dojo')->assertSee('As suas aulas como professor.')
            ->assertSee(route('operation.session', [$this->dojo, $mine]), false)->assertDontSee('Turma da outra')->assertSee('class="teacher-tabbar"', false);
        $this->get(route('teacher.classes'))->assertOk()->assertSee('Turma A')->assertDontSee('Turma da outra');
    }

    public function test_class_without_curriculum_shows_empty_coverage(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $group = $this->group($ana);
        $student = $this->student('A-1');
        $student->martialArts()->first()->update(['current_rank_id' => Rank::query()->where('name', 'laranja')->value('id')]);
        $this->enroll($group, $student);

        $coverage = app(CurriculumCoverageService::class)->forClass($this->association, $group, today()->subDays(90), today());
        $this->assertSame(0, $coverage['overall']['total']);
        $this->assertCount(1, $coverage['without_next']);
        $this->actingAs($anaUser)->get(route('operation.classes.coverage', [$this->dojo, $group]))->assertOk()
            ->assertSee('Sem currículo para comparar')->assertDontSee('Mapa aula a aula')->assertSee('1 aluno(s) já estão na última faixa');
    }
}
