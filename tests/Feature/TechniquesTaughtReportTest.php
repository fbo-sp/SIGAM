<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class TechniquesTaughtReportTest extends TestCase
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

    public function test_report_summarizes_closed_sessions_against_next_rank_curriculum(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $this->grant($anaUser, $this->role('teacher', ['report.export']));
        $group = $this->group($ana);
        $this->enroll($group, $this->student('A-1'));
        $this->enroll($group, $this->student('A-2'));
        $mark = function (string $date, string $state, array $items) use ($group, $anaUser) {
            $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => $date, 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => $state]);
            foreach ($items as $key) {
                ClassSessionTechnique::query()->create(['association_id' => $this->association->id, 'class_session_id' => $session->id, 'technique_item_id' => $this->items[$key]->id, 'marked_by_user_id' => $anaUser->id]);
            }
        };
        $mark('2026-10-05', 'closed', ['O-goshi', 'kano']);
        $mark('2026-10-12', 'closed', ['O-goshi']);
        $mark('2026-10-19', 'open', ['Kesa-gatame']);

        $this->actingAs($anaUser)->get(route('teacher.reports.index'))->assertOk()->assertSee('Técnicas ministradas')->assertDontSee('Em breve');
        $response = $this->get(route('teacher.reports.techniques', ['tecnicas_turma' => $group->id, 'de' => '2026-10-01', 'ate' => '2026-10-31']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tec').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = (new Xlsx)->setIncludeCharts(true)->load($path);
        unlink($path);

        $summary = $book->getSheetByName('Resumo');
        $this->assertEquals([2, 2, 5, 2, 0.4, 3], array_map(fn ($row) => $summary->getCell('B'.$row)->getValue(), range(5, 10)));
        $this->assertCount(2, $summary->getChartCollection());
        $groups = collect(range(13, $summary->getHighestDataRow('A')))->mapWithKeys(fn ($row) => [$summary->getCell('A'.$row)->getValue() => [$summary->getCell('B'.$row)->getValue(), $summary->getCell('C'.$row)->getValue(), $summary->getCell('D'.$row)->getValue()]]);
        $this->assertEquals([2, 1, 1], $groups['Nage-waza']);
        $this->assertEquals([0, 0, 1], $groups['Katame-waza']);

        $byItem = collect($book->getSheetByName('Por técnica')->toArray(null, true, false, true))->slice(5)->keyBy('B');
        $this->assertEquals([2, 1.0, 2, '■', '■'], [$byItem['O-goshi']['C'], $byItem['O-goshi']['D'], $byItem['O-goshi']['G'], $byItem['O-goshi']['H'], $byItem['O-goshi']['I']]);
        $this->assertNull($byItem['Jigoro Kano']['I']);
        $this->assertSame(0, $byItem['Kesa-gatame']['C']);
        $this->assertSame('05/10', $book->getSheetByName('Por técnica')->getCell('H5')->getValue());

        $missing = collect($book->getSheetByName('Não abordadas')->toArray(null, true, true, true))->slice(5)->keyBy('B');
        $this->assertSame(['Kesa-gatame', 'Nage-no-kata', 'Shido'], $missing->keys()->sort()->values()->all());
        $this->assertSame('Faixa amarela', $missing['Kesa-gatame']['C']);
        $this->assertSame('19/10/2026', $missing['Kesa-gatame']['E']);
        $this->assertSame('Nunca', $missing['Shido']['E']);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'report.exported']);

        $this->get(route('teacher.reports.techniques', ['de' => '2026-10-01', 'ate' => '2026-10-31']))->assertSessionHasErrors('tecnicas_turma');
    }
}
