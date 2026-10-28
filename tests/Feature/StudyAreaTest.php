<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\TechniqueAssessment;
use App\Models\TechniqueItem;
use App\Models\TechniqueStudyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class StudyAreaTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
        Storage::fake('local');
    }

    public function test_manager_fills_study_content_and_sheet_in_the_catalog(): void
    {
        $manager = $this->associationUser(['martial_art.view', 'curriculum.manage']);
        $item = $this->items['O-goshi'];
        $url = route('associations.techniques.update', [$this->association, $this->art, $item]);
        $base = ['name' => $item->name, 'kind' => 'item', 'parent_id' => $item->parent_id];

        $this->actingAs($manager)->get(route('associations.techniques.edit', [$this->association, $this->art, $item]))->assertOk()->assertSee('Área de estudo do aluno')->assertSee('O que o professor avalia');
        $this->put($url, $base + ['key_points' => "1\n2\n3\n4\n5\n6"])->assertSessionHasErrors('key_points');
        $this->put($url, $base + ['sheet' => UploadedFile::fake()->create('ficha.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('sheet');
        $this->put($url, $base + [
            'translation' => 'grande quadril', 'video_url' => 'https://youtu.be/abcdefghijk',
            'key_points' => "quadril encaixado\n\nbraço envolve a cintura", 'common_error' => 'ficar de frente sem girar',
            'steps' => "Prepare: puxe\nAjuste: gire\nControle: projete", 'sheet' => UploadedFile::fake()->image('o-goshi.png', 400, 600),
        ])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(['quadril encaixado', 'braço envolve a cintura'], $item->keyPointList());
        $this->assertSame('https://www.youtube-nocookie.com/embed/abcdefghijk', $item->videoEmbedUrl());
        $this->assertSame('image/png', $item->sheet_mime);
        Storage::disk('local')->assertExists($item->sheet_path);
        $this->get(route('techniques.sheet', $item))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'technique_item.sheet_saved']);

        $old = $item->sheet_path;
        $this->put($url, $base + ['translation' => 'grande quadril', 'remove_sheet' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->sheet_path);
        Storage::disk('local')->assertMissing($old);
        $this->get(route('techniques.sheet', $item))->assertNotFound();
    }

    public function test_student_studies_the_catalog_and_teacher_sees_home_study(): void
    {
        [$teacherUser, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $student = $this->student('A-1');
        $this->enroll($group, $student);
        $portal = $this->portalUser($student->person);
        $ogoshi = $this->items['O-goshi'];
        $ogoshi->update(['translation' => 'grande quadril', 'video_url' => 'https://vimeo.com/123456789', 'key_points' => "quadril encaixado\nbraço na cintura", 'common_error' => 'não girar', 'steps' => "Prepare\nAjuste\nControle"]);
        TechniqueAssessment::query()->create(['association_id' => $this->association->id, 'student_id' => $student->id, 'martial_art_id' => $this->art->id, 'technique_item_id' => $ogoshi->id,
            'level' => 2, 'teacher_profile_id' => $teacher->id, 'assessed_by_user_id' => $teacherUser->id, 'assessed_on' => '2026-10-10', 'student_tip' => 'Gire mais o quadril.']);
        $extra = $this->item('Uki-goshi', 'practical', $ogoshi->parent);

        $index = route('portal.study', $student);
        $this->actingAs($portal)->get($index)->assertOk()->assertSee('Estudar')->assertSee('O professor pediu atenção em 1 item(ns).')
            ->assertSee('O-goshi')->assertDontSee('Uki-goshi');
        $this->get(route('portal.study', [$student, 'filtro' => 'todas']))->assertOk()->assertSee('Uki-goshi')->assertSee('Jigoro Kano');
        $this->get(route('portal.study', [$student, 'q' => 'quadril']))->assertOk()->assertSee('O-goshi')->assertDontSee('Kesa-gatame');
        $this->get(route('portal.students.show', $student))->assertOk()->assertSee('href="'.$index.'"', false);

        $show = route('portal.study.item', [$student, $ogoshi]);
        $this->get($show)->assertOk()->assertSee('grande quadril')->assertSee('A partir de Faixa amarela')->assertSee('Gire mais o quadril.')
            ->assertSee('https://player.vimeo.com/video/123456789', false)->assertSee('quadril encaixado')->assertSee('não girar')->assertSee('Estudei esta técnica')
            ->assertDontSee('Abrir ficha da técnica');
        $ogoshi->update(['sheet_path' => 'technique-sheets/'.$this->association->id.'/o-goshi.jpg', 'sheet_mime' => 'image/jpeg', 'sheet_name' => 'o-goshi.jpg']);
        $this->get($show)->assertOk()->assertSee('Abrir ficha da técnica')->assertSee('href="'.route('techniques.sheet', $ogoshi).'"', false)
            ->assertDontSee('<img src="'.route('techniques.sheet', $ogoshi), false);
        $this->get(route('portal.study.item', [$student, $extra]))->assertOk()->assertSee('não está no currículo da sua próxima faixa')->assertSee('ainda não incluiu vídeo');
        $this->get(route('portal.study.item', [$student, $ogoshi->parent]))->assertNotFound();

        $this->post(route('portal.study.studied', [$student, $ogoshi]))->assertSessionHas('status', 'Estudo registrado. O professor vê na sua ficha de acompanhamento.');
        $this->post(route('portal.study.studied', [$student, $ogoshi]))->assertSessionHas('status', 'O estudo de hoje já estava registrado.');
        $this->travel(1)->days();
        $this->post(route('portal.study.studied', [$student, $ogoshi]));
        $this->assertSame(2, TechniqueStudyLog::query()->count());
        $this->get($show)->assertSee('Estudo de hoje registrado')->assertSee('Você estudou 2x');

        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra', 'status' => 'active']);
        $foreign = TechniqueItem::query()->create(['association_id' => $other->id, 'martial_art_id' => $this->art->id, 'kind' => 'item', 'knowledge_type' => 'practical', 'name' => 'Alheio', 'status' => 'active']);
        $this->get(route('portal.study.item', [$student, $foreign]))->assertNotFound();
        $this->post(route('portal.study.studied', [$student, $foreign]))->assertNotFound();

        $this->actingAs($teacherUser)->get(route('operation.progression.show', [$this->dojo, $student]))->assertOk()
            ->assertSee('Estudo em casa')->assertSee('estudou em casa 2x');
    }
}
