<?php

namespace Tests\Feature;

use App\Models\ClassGroupTeacher;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\Dojo;
use App\Models\Rank;
use App\Models\StudentGuardian;
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
        $white->person->update(['emergency_contact_name' => 'Maria Avó', 'emergency_contact_phone' => '(11) 98888-7777']);
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

        $this->actingAs($anaUser)->get(route('dashboard'))->assertOk()
            ->assertSee(route('operation.classes.show', [$this->dojo, $group]), false)
            ->assertSee('Principal · 2 aluno(s)')->assertSee('Cobertura do currículo')
            ->assertSee(route('operation.classes.coverage', [$this->dojo, $group]), false)->assertSee('1 de 5 itens trabalhados');
        $this->actingAs($brunoUser)->get(route('dashboard'))->assertOk()->assertSee('Turma A')->assertSee('Auxiliar · 2 aluno(s)')
            ->assertSee(route('operation.classes.show', [$this->dojo, $group]), false);

        $this->actingAs($anaUser)->get(route('operation.classes.show', [$this->dojo, $group]))->assertOk()
            ->assertSee('← Voltar ao painel')->assertSee('data-modal-open="emergencia-'.$white->id.'"', false)
            ->assertSee('Maria Avó')->assertSee('href="tel:11988887777"', false)
            ->assertSee('Responsável legal')->assertSee('Carlos Pai')->assertSee('href="tel:11977776666"', false)
            ->assertSee('menor de idade')->assertSee('Cobertura do currículo');

        $this->get(route('operation.classes.coverage', [$this->dojo, $group]))->assertOk()
            ->assertSeeInOrder(['Visão geral da turma', '1 de 5 itens já trabalhados', 'Por faixa', 'Rumo a Faixa amarela', 'Rumo a Faixa laranja'])
            ->assertSee('Distribuição por grupo')->assertSee('Nage-waza · out: 1 aula(s)')->assertSee('Mapa aula a aula')->assertSee('14/10: trabalhado')->assertSee('Ainda não trabalhado (4)')->assertSee('Kesa-gatame')->assertSee('← Voltar ao painel');
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
