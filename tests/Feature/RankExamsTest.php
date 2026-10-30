<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\QuizQuestion;
use App\Models\RankCurriculumEntry;
use App\Models\RankExam;
use App\Models\RankExamGroup;
use App\Models\RankExamItem;
use App\Models\RankExamParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class RankExamsTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
        $this->travelTo('2026-10-20 08:00');
    }

    public function test_association_manager_builds_participants_and_content_and_opens_the_exam(): void
    {
        $this->items['rei'] = $this->item('Rei', 'theoretical', $this->items['kano']->parent);
        RankCurriculumEntry::query()->create(['association_id' => $this->association->id, 'rank_id' => $this->white->id, 'technique_item_id' => $this->items['rei']->id, 'required_level' => 1]);
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $group = $this->group($ana);
        $clara = $this->student('Clara');
        $pedro = $this->student('Pedro', '2000-01-01', '2026-08-01');
        $davi = $this->student('Davi', null);
        foreach ([$clara, $pedro, $davi] as $student) {
            $this->enroll($group, $student);
        }
        $this->classSession($group, '2026-10-05', 'closed', [$clara->id => 'present', $pedro->id => 'absent'], [$this->items['O-goshi'], $this->items['Kesa-gatame']->parent]);
        $this->classSession($group, '2026-10-12', 'closed', [$pedro->id => 'present'], [$this->items['Nage-no-kata']]);
        $this->classSession($group, '2026-10-19', 'open', [$clara->id => 'present'], [$this->items['kano']]);
        $question = QuizQuestion::query()->create(['association_id' => $this->association->id, 'martial_art_id' => $this->art->id, 'rank_id' => $this->yellow->id, 'statement' => 'Quem criou o judô?', 'status' => 'active']);
        $question->options()->create(['text' => 'Jigoro Kano', 'is_correct' => true]);
        $manager = $this->associationUser(['association.view', 'exam.manage']);

        $this->actingAs($manager)->get(route('associations.exams.index', $this->association))->assertOk()->assertSee('Exames de graduação')->assertSee('Novo exame');
        $this->post(route('associations.exams.store', $this->association), [
            'name' => 'Exame de dezembro', 'martial_art_id' => $this->art->id, 'scope_type' => 'association', 'starts_on' => '2026-10-20', 'ends_on' => '2026-12-12',
            'minimum_average' => '7,5', 'class_teachers_examine' => '1', 'quiz_enabled' => '1', 'quiz_question_count' => 5,
        ])->assertSessionHasNoErrors();
        $exam = RankExam::query()->sole();
        $this->assertSame(['association', 'draft', '7.50', 5], [$exam->scope_type, $exam->status, $exam->minimum_average, $exam->quiz_question_count]);

        $this->get(route('associations.exams.participants', [$this->association, $exam]))->assertOk()
            ->assertSee('Não apto')->assertSee('2 meses na faixa (mínimo 6)')->assertSee('sem data de nascimento');
        $students = fn (?string $reason) => [$clara->id => ['include' => '1'], $pedro->id => ['include' => '1', 'reason' => $reason], $davi->id => ['include' => '1']];
        $this->put(route('associations.exams.participants.save', [$this->association, $exam]), ['students' => $students(null)])->assertSessionHasErrors("students.{$pedro->id}.reason");
        $this->assertSame(0, $exam->participants()->count());
        $this->put(route('associations.exams.participants.save', [$this->association, $exam]), ['students' => $students('Treinou em dobro no semestre')])
            ->assertRedirect(route('associations.exams.content', [$this->association, $exam]));
        $this->assertSame(3, $exam->enrolled()->count());
        $this->assertFalse(RankExamParticipant::query()->where('student_id', $pedro->id)->sole()->eligible);

        $groups = RankExamGroup::query()->where('rank_exam_id', $exam->id)->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Kata', 'Katame-waza', 'Nage-waza', 'Teoria'], $groups);
        $item = fn (string $key) => RankExamItem::query()->where('rank_exam_id', $exam->id)->where('technique_item_id', $this->items[$key]->id)->sole();
        $this->assertSame([true, true, false], [$item('kano')->selected, $item('shido')->selected, $item('rei')->selected]);
        $this->assertSame(['new', 'inherited'], [$item('kano')->origin, $item('rei')->origin]);
        $quiz = RankExamItem::query()->where('rank_exam_id', $exam->id)->where('kind', 'quiz')->sole();
        $this->assertSame('Teoria', $quiz->group->name);

        $this->get(route('associations.exams.content', [$this->association, $exam]))->assertOk()
            ->assertSee('O-goshi')->assertSee('1/2 aulas')->assertSee('0/2 aulas')->assertSee('Quiz do exame')->assertSee('1 pergunta(s) da faixa');
        $this->get(route('associations.exams.show', [$this->association, $exam]))->assertOk()->assertSee('2 item(ns) sem aula precisam de justificativa');
        $this->post(route('associations.exams.open', [$this->association, $exam]))->assertSessionHasErrors('exam');

        $payload = [
            $item('kano')->id => ['selected' => '1', 'justification' => ''],
            $item('shido')->id => ['selected' => '0'],
        ];
        $this->put(route('associations.exams.content.save', [$this->association, $exam, $this->yellow]), ['items' => $payload])->assertSessionHasErrors("items.{$item('kano')->id}.justification");
        $payload[$item('kano')->id]['justification'] = 'Trabalhado na palestra de outubro';
        $payload[$item('Nage-no-kata')->id] = ['selected' => '1', 'group' => RankExamGroup::query()->where('rank_exam_id', $exam->id)->where('name', 'Nage-waza')->value('id')];
        foreach (['O-goshi', 'Kesa-gatame'] as $key) {
            $payload[$item($key)->id] = ['selected' => '1'];
        }
        $this->put(route('associations.exams.content.save', [$this->association, $exam, $this->yellow]), ['items' => $payload])->assertSessionHasNoErrors();
        $this->assertFalse($item('shido')->selected);
        $this->assertSame('Nage-waza', $item('Nage-no-kata')->group->name);
        $this->assertFalse($item('rei')->selected);

        $this->post(route('associations.exams.open', [$this->association, $exam]))->assertSessionHasNoErrors();
        $this->assertSame('open', $exam->fresh()->status);
        foreach (['rank_exam.created', 'rank_exam.participants_saved', 'rank_exam.content_saved', 'rank_exam.opened'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event_type' => $event, 'entity_id' => $exam->id]);
        }

        $this->put(route('associations.exams.participants.save', [$this->association, $exam]), ['students' => [$clara->id => ['include' => '1'], $pedro->id => ['include' => '0'], $davi->id => ['include' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame('removed', RankExamParticipant::query()->where('student_id', $pedro->id)->sole()->status);
        $this->assertSame(3, RankExamParticipant::query()->count());
    }

    public function test_dojo_manager_and_teacher_bank_see_only_their_exams(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->grant($anaUser, $this->role('teacher', ['exam.grade']));
        [$biaUser] = $this->teacher('Professora Bia');
        $this->grant($biaUser, $this->role('teacher', ['exam.grade']));
        $group = $this->group($ana);
        $clara = $this->student('Clara');
        $this->enroll($group, $clara);
        $manager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($manager, $this->role('dojo', ['dojo.access', 'exam.manage']));
        $data = ['name' => 'Exame da turma', 'martial_art_id' => $this->art->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-30', 'minimum_average' => '7'];

        $this->actingAs($manager)->get(route('operation.home', $this->dojo))->assertOk()->assertSee('Exames de graduação');
        $this->post(route('operation.exams.store', $this->dojo), $data + ['scope_type' => 'association'])->assertSessionHasErrors('scope_type');
        $this->post(route('operation.exams.store', $this->dojo), $data + ['scope_type' => 'class', 'class_group_id' => $group->id, 'class_teachers_examine' => '1'])->assertSessionHasNoErrors();
        $exam = RankExam::query()->sole();
        $this->assertSame([$this->dojo->id, $group->id], [$exam->dojo_id, $exam->class_group_id]);
        $this->put(route('operation.exams.participants.save', [$this->dojo, $exam]), ['students' => [$clara->id => ['include' => '1']]])->assertSessionHasNoErrors();
        $this->get(route('associations.exams.index', $this->association))->assertForbidden();

        $other = RankExam::query()->create(['association_id' => $this->association->id, 'martial_art_id' => $this->art->id, 'name' => 'Outro exame', 'scope_type' => 'association', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-30', 'status' => 'draft', 'created_by_user_id' => $manager->id]);
        $this->get(route('operation.exams.show', [$this->dojo, $other]))->assertNotFound();
        $this->get(route('operation.exams.index', [$this->dojo, 'aba' => 'rascunhos']))->assertOk()->assertSee('Exame da turma')->assertDontSee('Outro exame');

        $this->actingAs($anaUser)->get(route('teacher.exams.index', ['aba' => 'rascunhos']))->assertOk()->assertSee('Exame da turma')->assertDontSee('Novo exame');
        $this->get(route('teacher.exams.content', $exam))->assertOk()->assertSee('O-goshi')->assertDontSee('Novo grupo de nota');
        $kesa = RankExamItem::query()->where('rank_exam_id', $exam->id)->where('technique_item_id', $this->items['Kesa-gatame']->id)->sole();
        $items = RankExamItem::query()->where('rank_exam_id', $exam->id)->where('kind', 'technique')->get()
            ->mapWithKeys(fn (RankExamItem $item) => [$item->id => ['selected' => $item->id === $kesa->id ? '1' : '0', 'justification' => 'Treinado no estágio']])->all();
        $this->put(route('teacher.exams.content.save', [$exam, $this->yellow]), ['items' => $items])->assertSessionHasNoErrors();
        $this->assertSame(1, RankExamItem::query()->where('rank_exam_id', $exam->id)->where('selected', true)->count());
        $this->post(route('operation.exams.open', [$this->dojo, $exam]))->assertForbidden();

        $this->actingAs($biaUser)->get(route('teacher.exams.index', ['aba' => 'rascunhos']))->assertOk()->assertDontSee('Exame da turma');
        $this->get(route('teacher.exams.show', $exam))->assertNotFound();

        $this->actingAs($manager)->post(route('operation.exams.store', $this->dojo), $data + ['scope_type' => 'student', 'student_id' => $clara->id])->assertSessionHasErrors('justification');
        $this->post(route('operation.exams.store', $this->dojo), $data + ['scope_type' => 'student', 'student_id' => $clara->id, 'justification' => 'Voltou de lesão'])->assertSessionHasNoErrors();
        $single = RankExam::query()->where('scope_type', 'student')->sole();
        $this->put(route('operation.exams.participants.save', [$this->dojo, $single]), ['students' => [$clara->id => ['include' => '1']]])
            ->assertSessionHasErrors("students.{$clara->id}.include");
        $this->post(route('operation.exams.cancel', [$this->dojo, $single]), ['reason' => 'Criado por engano'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $single->fresh()->status);
    }

    /** @param  array<int, string>  $presence */
    private function classSession(ClassGroup $group, string $date, string $state, array $presence, array $items): ClassSession
    {
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => $state]);
        $user = User::query()->firstOrFail();
        foreach ($presence as $studentId => $status) {
            AttendanceRecord::query()->create(['class_session_id' => $session->id, 'student_id' => $studentId, 'status' => $status, 'recorded_at' => now(), 'recorded_by_user_id' => $user->id]);
        }
        foreach ($items as $item) {
            ClassSessionTechnique::query()->create(['association_id' => $this->association->id, 'class_session_id' => $session->id, 'technique_item_id' => $item->id, 'marked_by_user_id' => $user->id]);
        }

        return $session;
    }
}
