<?php

namespace Tests\Feature;

use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\QuizQuestion;
use App\Models\QuizRelease;
use App\Models\Rank;
use App\Models\Student;
use App\Models\TechniqueAssessment;
use App\Models\User;
use App\Support\Progression\AssessmentLevel;
use App\Support\Progression\TechniqueTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class QuizTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_association_manages_question_bank_and_settings(): void
    {
        $manager = $this->associationUser(['martial_art.view', 'quiz.manage', 'curriculum.manage']);
        $url = route('associations.quiz.index', [$this->association, $this->art]);

        $this->actingAs($manager)->get($url)->assertOk()->assertSee('Banco de perguntas do quiz');
        $this->post(route('associations.quiz.store', [$this->association, $this->art]), [
            'rank_id' => $this->yellow->id, 'technique_item_id' => $this->items['kano']->id, 'statement' => 'Quem criou o judô?',
            'options' => ['Jigoro Kano', 'Mifune', '', ''], 'correct' => 0,
        ])->assertSessionHasNoErrors();
        $question = QuizQuestion::query()->sole();
        $this->assertSame(['Jigoro Kano', 'Mifune'], $question->options()->pluck('text')->all());

        $this->post(route('associations.quiz.store', [$this->association, $this->art]), ['rank_id' => $this->yellow->id, 'technique_item_id' => $this->items['O-goshi']->id, 'statement' => 'x', 'options' => ['a', 'b'], 'correct' => 0])
            ->assertSessionHasErrors('technique_item_id');
        $this->post(route('associations.quiz.store', [$this->association, $this->art]), ['rank_id' => $this->yellow->id, 'statement' => 'x', 'options' => ['a', ''], 'correct' => 0])
            ->assertSessionHasErrors('options');

        QuizAttemptAnswer::query()->create(['quiz_attempt_id' => $this->attemptFor($this->student('A-9'))->id, 'quiz_question_id' => $question->id, 'option_ids' => [], 'sort_order' => 1]);
        $this->put(route('associations.quiz.update', [$this->association, $this->art, $question]), ['rank_id' => $this->yellow->id, 'statement' => 'Quem fundou o judô?', 'options' => ['Jigoro Kano', 'Outro'], 'correct' => 0])
            ->assertSessionHasErrors('options');
        $this->put(route('associations.quiz.update', [$this->association, $this->art, $question]), ['rank_id' => $this->yellow->id, 'statement' => 'Quem fundou o judô?', 'options' => ['Jigoro Kano', 'Mifune'], 'correct' => 0])
            ->assertSessionHasNoErrors();
        $this->assertSame('Quem fundou o judô?', $question->fresh()->statement);

        $this->put(route('associations.progression.settings.update', $this->association), ['quiz_question_count' => 5, 'quiz_minimum_score' => 80, 'assessment_reminder_weeks' => 6])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('progression_settings', ['association_id' => $this->association->id, 'quiz_question_count' => 5, 'quiz_minimum_score' => 80]);

        $this->actingAs($this->associationUser(['martial_art.view']))->get($url)->assertForbidden();
    }

    public function test_portal_progress_starts_with_profile_and_opens_each_part(): void
    {
        $student = $this->student('A-1');
        $portal = $this->portalUser($student->person);
        $hub = route('portal.progress', $student);

        $this->actingAs($portal)->get($hub)->assertOk()->assertSee('radar-card')
            ->assertSeeInOrder(['Perfil técnico', 'Quiz teórico', 'O que falta', 'Nas aulas', 'Evolução'])
            ->assertSee('não treinado(s)')->assertSee('0 aula(s) em 90 dias')->assertSee('Nenhuma avaliação')
            ->assertSee('href="'.route('portal.progress.part', [$student, 'falta']).'"', false)->assertDontSee('Requisitos do exame');
        $this->get(route('portal.progress.part', [$student, 'falta']))->assertOk()->assertSee('← Progresso')->assertSee('href="'.$hub.'"', false)
            ->assertSee('Requisitos do exame')->assertSee('<details class="card progress-group">', false)->assertSee('Ainda não treinado (')->assertSee('já no nível pedido')->assertDontSee('Perfil técnico');
        $this->get(route('portal.progress.part', [$student, 'quiz']))->assertOk()->assertSee('Treinar');
        $this->get(route('portal.progress.part', [$student, 'aulas']))->assertOk()->assertSee('Nenhuma técnica registrada');
        $this->get(route('portal.progress.part', [$student, 'evolucao']))->assertOk()->assertSee('Nenhuma avaliação ainda.');
        $this->get('/portal/alunos/'.$student->id.'/progresso/outra')->assertNotFound();

        $student->martialArts()->first()->update(['current_rank_id' => Rank::query()->where('name', 'laranja')->value('id')]);
        $this->get($hub)->assertOk()->assertSee('Você está na última graduação cadastrada')->assertSee('Última graduação')->assertDontSee('radar-card');
    }

    public function test_training_is_free_and_official_needs_release_with_single_attempt(): void
    {
        [$teacherUser, $teacher] = $this->teacher('Professora Ana');
        $student = $this->student('A-1');
        $this->enroll($this->group($teacher), $student);
        $kano = $this->question('Quem criou o judô?', $this->items['kano']->id);
        $shido = $this->question('O que é shido?', $this->items['shido']->id);
        $this->question('Pergunta da faixa branca', null, $this->white->id);
        $portal = $this->portalUser($student->person);

        $this->actingAs($portal)->get(route('portal.progress.part', [$student, 'quiz']))->assertOk()->assertSee('Treinar')->assertDontSee('Fazer prova oficial');
        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'training'])->assertRedirect();
        $training = QuizAttempt::query()->sole();
        $this->assertSame(3, $training->question_count);
        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'training']);
        $this->assertSame(1, QuizAttempt::query()->count());
        $this->get(route('portal.quiz.attempt', [$student, $training]))->assertOk()->assertSee('Quem criou o judô?')->assertDontSee('· correta');

        $this->post(route('portal.quiz.submit', [$student, $training]), ['answers' => [$kano->id => $this->correct($kano), $shido->id => $this->wrong($shido)]])
            ->assertSessionHas('status', 'Quiz finalizado: 33% de acertos.');
        $this->post(route('portal.quiz.submit', [$student, $training]), ['answers' => []]);
        $this->assertSame(33, $training->fresh()->score);
        $this->assertSame(0, TechniqueAssessment::query()->count());

        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'official', 'release' => 999])->assertNotFound();

        $this->actingAs($teacherUser)->post(route('operation.progression.quiz.release', [$this->dojo, $student, $this->art]))->assertSessionHasNoErrors();
        $this->post(route('operation.progression.quiz.release', [$this->dojo, $student, $this->art]));
        $release = QuizRelease::query()->sole();
        $this->assertSame($this->yellow->id, $release->rank_id);
        $this->get(route('operation.progression.show', [$this->dojo, $student]))->assertSee('Prova oficial liberada para Faixa amarela');

        $this->actingAs($portal)->get(route('portal.progress.part', [$student, 'quiz']))->assertSee('Fazer prova oficial');
        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'official', 'release' => $release->id]);
        $official = QuizAttempt::query()->where('mode', 'official')->sole();
        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'official', 'release' => $release->id]);
        $this->assertSame(1, QuizAttempt::query()->where('mode', 'official')->count());

        $this->actingAs($teacherUser)->post(route('operation.progression.quiz.cancel', [$this->dojo, $student, $release]))->assertSessionHasErrors('quiz');

        $answers = QuizQuestion::query()->get()->mapWithKeys(fn (QuizQuestion $question) => [$question->id => $this->correct($question)])->all();
        $this->actingAs($portal)->post(route('portal.quiz.submit', [$student, $official]), ['answers' => $answers]);
        $official->refresh();
        $this->assertSame(100, $official->score);
        $this->assertTrue($official->passed);
        $this->assertSame('used', $release->fresh()->status);
        $this->assertEqualsCanonicalizing([$this->items['kano']->id, $this->items['shido']->id], TechniqueAssessment::query()->where('level', AssessmentLevel::ADEQUATE)->pluck('technique_item_id')->all());
        $this->assertSame($teacher->id, TechniqueAssessment::query()->first()->teacher_profile_id);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'quiz_attempt.finished']);

        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'official', 'release' => $release->id])->assertRedirect(route('portal.quiz.attempt', [$student, $official]));
        $this->get(route('portal.quiz.attempt', [$student, $official]))->assertOk()->assertSee('Aprovado');
    }

    public function test_failed_official_does_not_mark_items_and_teacher_can_cancel_unused_release(): void
    {
        [$teacherUser, $teacher] = $this->teacher('Professora Ana');
        $student = $this->student('A-1');
        $this->enroll($this->group($teacher), $student);
        $kano = $this->question('Quem criou o judô?', $this->items['kano']->id);
        $portal = $this->portalUser($student->person);

        $this->actingAs($teacherUser)->post(route('operation.progression.quiz.release', [$this->dojo, $student, $this->art]));
        $release = QuizRelease::query()->sole();
        $this->post(route('operation.progression.quiz.cancel', [$this->dojo, $student, $release]))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $release->fresh()->status);
        $this->actingAs($portal)->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'official', 'release' => $release->id])->assertSessionHasErrors('quiz');

        $this->actingAs($teacherUser)->post(route('operation.progression.quiz.release', [$this->dojo, $student, $this->art]));
        $release = QuizRelease::query()->where('status', 'open')->sole();
        $this->actingAs($portal)->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'official', 'release' => $release->id]);
        $attempt = QuizAttempt::query()->sole();
        $this->post(route('portal.quiz.submit', [$student, $attempt]), ['answers' => [$kano->id => $this->wrong($kano)]]);
        $this->assertFalse($attempt->fresh()->passed);
        $this->assertSame(0, TechniqueAssessment::query()->count());
    }

    public function test_portal_isolation_and_only_assessor_releases(): void
    {
        [, $teacher] = $this->teacher('Professora Ana');
        [$outsiderUser] = $this->teacher('Professor Bruno');
        $student = $this->student('A-1');
        $other = $this->student('A-2');
        $this->enroll($this->group($teacher), $student);
        $this->question('Quem criou o judô?', $this->items['kano']->id);
        $portal = $this->portalUser($other->person);

        $this->actingAs($portal)->get(route('portal.progress', $student))->assertNotFound();
        $this->post(route('portal.quiz.start', [$student, $this->art]), ['mode' => 'training'])->assertNotFound();
        $attempt = $this->attemptFor($student);
        $this->get(route('portal.quiz.attempt', [$other, $attempt]))->assertNotFound();
        $this->post(route('portal.quiz.submit', [$other, $attempt]), ['answers' => []])->assertNotFound();

        $this->actingAs($outsiderUser)->post(route('operation.progression.quiz.release', [$this->dojo, $student, $this->art]))->assertForbidden();
        $this->assertSame(0, QuizRelease::query()->count());
    }

    public function test_template_brings_quiz_questions_once(): void
    {
        $manager = $this->associationUser(['martial_art.view', 'curriculum.manage']);
        $catalog = app(TechniqueTemplateCatalog::class);
        $template = $catalog->find('judo_kodokan_2026');
        $this->assertNotEmpty($template['quiz']);

        $this->actingAs($manager)->post(route('associations.techniques.template', [$this->association, $this->art]), ['template_key' => 'judo_kodokan_2026']);
        $first = QuizQuestion::query()->count();
        $this->post(route('associations.techniques.template', [$this->association, $this->art]), ['template_key' => 'judo_kodokan_2026']);
        $this->assertSame($first, QuizQuestion::query()->count());
    }

    private function question(string $statement, ?int $itemId, ?int $rankId = null): QuizQuestion
    {
        $question = QuizQuestion::query()->create(['association_id' => $this->association->id, 'martial_art_id' => $this->art->id, 'rank_id' => $rankId ?? $this->yellow->id, 'technique_item_id' => $itemId, 'statement' => $statement, 'status' => 'active']);
        foreach (['Certa', 'Errada', 'Outra'] as $index => $text) {
            $question->options()->create(['text' => $text, 'is_correct' => $index === 0, 'sort_order' => $index + 1]);
        }

        return $question;
    }

    private function correct(QuizQuestion $question): int
    {
        return $question->options()->where('is_correct', true)->value('id');
    }

    private function wrong(QuizQuestion $question): int
    {
        return $question->options()->where('is_correct', false)->value('id');
    }

    private function attemptFor(Student $student): QuizAttempt
    {
        return QuizAttempt::query()->create(['association_id' => $this->association->id, 'student_id' => $student->id, 'martial_art_id' => $this->art->id, 'rank_id' => $this->yellow->id, 'mode' => 'training', 'user_id' => User::factory()->create()->id, 'status' => 'in_progress', 'question_count' => 1, 'minimum_score' => 70, 'started_at' => now()]);
    }
}
