<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Rank;
use App\Models\RankCurriculumEntry;
use App\Models\Role;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\Progression\CurriculumService;
use App\Services\Sports\CatalogService;
use App\Support\Progression\TechniqueTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechniqueCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private User $manager;

    private MartialArt $judo;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->association, $this->manager] = $this->context(['martial_art.view', 'curriculum.manage']);
        $this->judo = app(CatalogService::class)->createMartialArt($this->association, ['name' => 'Judô', 'status' => 'active'], 'judo_cbj_2023');
    }

    public function test_template_is_valid_and_matches_rank_template(): void
    {
        $template = app(TechniqueTemplateCatalog::class)->find('judo_kodokan_2026');
        $this->assertNotNull($template);
        $rankNames = $this->judo->ranks()->pluck('name')->all();
        foreach (array_keys($template['curriculum']) as $rankName) {
            $this->assertContains($rankName, $rankNames);
        }
    }

    public function test_applying_template_copies_catalog_and_curriculum_and_is_idempotent(): void
    {
        $this->actingAs($this->manager)->get(route('associations.techniques.index', [$this->association, $this->judo]))
            ->assertOk()->assertSee('Começar com um modelo pronto');

        $this->post(route('associations.techniques.template', [$this->association, $this->judo]), ['template_key' => 'judo_kodokan_2026'])
            ->assertSessionHasNoErrors();
        $items = TechniqueItem::query()->count();
        $entries = RankCurriculumEntry::query()->count();
        $this->assertGreaterThan(150, $items);
        $this->assertGreaterThan(100, $entries);
        $this->assertSame(0, TechniqueItem::query()->whereNull('parent_id')->whereNotIn('knowledge_type', TechniqueItem::KNOWLEDGE_TYPES)->count());
        $this->assertDatabaseHas('technique_items', ['name' => 'Nage-waza', 'parent_id' => null, 'knowledge_type' => 'practical']);
        $this->assertDatabaseHas('technique_items', ['name' => 'Conhecimento teórico', 'parent_id' => null, 'knowledge_type' => 'theoretical']);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $this->association->id, 'event_type' => 'technique_template.applied']);

        // Ajuste do gestor sobrevive a uma nova aplicação do modelo.
        $entry = RankCurriculumEntry::query()->firstOrFail();
        $entry->update(['required_level' => 4]);
        $this->post(route('associations.techniques.template', [$this->association, $this->judo]), ['template_key' => 'judo_kodokan_2026'])
            ->assertSessionHas('status', 'Modelo aplicado: 0 item(ns), 0 exigência(s) de currículo e 0 pergunta(s) de quiz incluídos.');
        $this->assertSame($items, TechniqueItem::query()->count());
        $this->assertSame($entries, RankCurriculumEntry::query()->count());
        $this->assertSame(4, $entry->fresh()->required_level);

        $this->get(route('associations.techniques.index', [$this->association, $this->judo]))->assertOk()
            ->assertSee('Conhecimento Teórico')->assertSee('Conhecimento Prático')->assertSee('Ippon-seoi-nage');
        $this->get(route('associations.curriculum.show', [$this->association, $this->judo]))->assertOk();
    }

    public function test_template_reports_ranks_missing_in_martial_art(): void
    {
        $other = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Judô adaptado', 'status' => 'active']);
        Rank::query()->create(['martial_art_id' => $other->id, 'name' => '11-kyu', 'display_name' => 'Branca', 'sort_order' => 1, 'status' => 'active']);

        $this->actingAs($this->manager)->post(route('associations.techniques.template', [$this->association, $other]), ['template_key' => 'judo_kodokan_2026'])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'Graduações não encontradas nesta modalidade: 10-kyu'));
        $this->assertSame(0, RankCurriculumEntry::query()->whereHas('rank', fn ($query) => $query->where('name', '!=', '11-kyu'))->count());
    }

    public function test_curriculum_inherits_raises_removes_and_restores(): void
    {
        [$first, $second, $third] = $this->judo->ranks()->orderBy('sort_order')->take(3)->get()->all();
        $root = $this->item('Nage-waza', 'group', 'practical');
        $seoi = $this->item('Ippon-seoi-nage', 'item', 'practical', $root);
        $osoto = $this->item('O-soto-gari', 'item', 'practical', $root);
        $history = $this->item('História', 'item', 'theoretical');
        $service = app(CurriculumService::class);

        $this->actingAs($this->manager)->post(route('associations.curriculum.store', [$this->association, $this->judo, $first]), ['technique_item_id' => $seoi->id, 'required_level' => 2])->assertSessionHasNoErrors();
        $this->post(route('associations.curriculum.store', [$this->association, $this->judo, $first]), ['technique_item_id' => $history->id, 'required_level' => 2])->assertSessionHasNoErrors();
        $this->post(route('associations.curriculum.store', [$this->association, $this->judo, $second]), ['technique_item_id' => $seoi->id, 'required_level' => 3])->assertSessionHasNoErrors();
        $this->post(route('associations.curriculum.store', [$this->association, $this->judo, $second]), ['technique_item_id' => $osoto->id, 'required_level' => 2])->assertSessionHasNoErrors();
        $this->post(route('associations.curriculum.store', [$this->association, $this->judo, $third]), ['technique_item_id' => $history->id, 'required_level' => 0])->assertSessionHasNoErrors();

        $second = $this->rows($service->effective($this->association, $second));
        $this->assertSame(['level' => 3, 'origin' => 'raised'], $second['Ippon-seoi-nage']);
        $this->assertSame(['level' => 2, 'origin' => 'new'], $second['O-soto-gari']);
        $this->assertSame(['level' => 2, 'origin' => 'inherited'], $second['História']);

        $effective = $service->effective($this->association, $third);
        $rows = $this->rows($effective);
        $this->assertSame(['Ippon-seoi-nage', 'O-soto-gari'], array_keys($rows));
        $this->assertSame('inherited', $rows['Ippon-seoi-nage']['origin']);
        $this->assertSame(['História'], array_map(fn (array $row) => $row['item']->name, $effective['removed']));
        $this->get(route('associations.curriculum.show', [$this->association, $this->judo, 'graduacao' => $third->id]))->assertOk()
            ->assertSee('Deixam de ser cobrados nesta graduação')->assertSee('Herdado de');

        $this->post(route('associations.curriculum.restore', [$this->association, $this->judo, $third, $history]))->assertSessionHasNoErrors();
        $this->assertArrayHasKey('História', $this->rows($service->effective($this->association, $third)));
        $this->assertDatabaseHas('audit_logs', ['association_id' => $this->association->id, 'event_type' => 'rank_curriculum.restored']);

        // Item inativo some do currículo efetivo sem apagar a exigência.
        $this->post(route('associations.techniques.status', [$this->association, $this->judo, $root]), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $seoi->fresh()->status);
        $this->assertSame(['História'], array_keys($this->rows($service->effective($this->association, $third))));
        $this->assertSame(4, RankCurriculumEntry::query()->whereNotNull('required_level')->count());
    }

    public function test_tree_rules(): void
    {
        $this->actingAs($this->manager);
        $route = route('associations.techniques.store', [$this->association, $this->judo]);
        $this->post($route, ['kind' => 'group', 'name' => 'Teoria'])->assertSessionHasErrors('knowledge_type');
        $this->post($route, ['kind' => 'group', 'knowledge_type' => 'theoretical', 'name' => 'Teoria'])->assertSessionHasNoErrors();
        $theory = TechniqueItem::query()->where('name', 'Teoria')->sole();
        $this->post($route, ['kind' => 'group', 'parent_id' => $theory->id, 'knowledge_type' => 'practical', 'name' => 'Regras'])->assertSessionHasNoErrors();
        $rules = TechniqueItem::query()->where('name', 'Regras')->sole();
        $this->assertSame('theoretical', $rules->knowledge_type);
        $this->post($route, ['kind' => 'item', 'parent_id' => $rules->id, 'name' => 'Shido'])->assertSessionHasNoErrors();
        $this->post($route, ['kind' => 'item', 'parent_id' => $rules->id, 'name' => 'shido'])->assertSessionHasErrors('name');
        $shido = TechniqueItem::query()->where('name', 'Shido')->sole();
        $this->post($route, ['kind' => 'item', 'parent_id' => $shido->id, 'name' => 'Dentro de técnica'])->assertSessionHasErrors('parent_id');

        $update = fn (TechniqueItem $item, array $data) => $this->put(route('associations.techniques.update', [$this->association, $this->judo, $item]), $data + ['name' => $item->name, 'kind' => $item->kind]);
        $update($theory, ['parent_id' => $rules->id])->assertSessionHasErrors('parent_id');
        $update($rules, ['parent_id' => $theory->id, 'kind' => 'item'])->assertSessionHasErrors('kind');
        $update($theory, ['knowledge_type' => 'practical'])->assertSessionHasNoErrors();
        $this->assertSame('practical', $shido->fresh()->knowledge_type);
        $update($shido, ['parent_id' => $rules->id, 'video_url' => 'javascript:alert(1)'])->assertSessionHasErrors('video_url');

        $this->post(route('associations.techniques.status', [$this->association, $this->judo, $theory]), ['status' => 'inactive']);
        $this->assertSame('inactive', $shido->fresh()->status);
        $this->post(route('associations.techniques.status', [$this->association, $this->judo, $shido]), ['status' => 'active'])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('audit_logs', ['association_id' => $this->association->id, 'event_type' => 'technique_item.status_changed']);
    }

    public function test_other_association_and_viewer_cannot_manage(): void
    {
        $item = $this->item('Nage-waza', 'group', 'practical');
        $rank = $this->judo->ranks()->first();
        [$other, $otherManager] = $this->context(['martial_art.view', 'curriculum.manage']);
        $this->actingAs($otherManager)->get(route('associations.techniques.index', [$other, $this->judo]))->assertNotFound();
        $global = MartialArt::query()->create(['name' => 'Global', 'status' => 'active']);
        $this->get(route('associations.techniques.index', [$other, $global]))->assertOk()->assertDontSee('<strong>Nage-waza</strong>', false);
        $this->get(route('associations.techniques.edit', [$this->association, $this->judo, $item]))->assertForbidden();

        $globalRank = Rank::query()->create(['martial_art_id' => $global->id, 'name' => 'b', 'display_name' => 'Branca', 'sort_order' => 1, 'status' => 'active']);
        $this->post(route('associations.curriculum.store', [$other, $global, $globalRank]), ['technique_item_id' => $item->id, 'required_level' => 2])->assertNotFound();
        $this->assertSame(0, RankCurriculumEntry::query()->count());

        [, $viewer] = $this->context(['martial_art.view'], $this->association);
        $this->actingAs($viewer)->get(route('associations.curriculum.show', [$this->association, $this->judo]))->assertOk()->assertDontSee('Salvar exigência');
        $this->post(route('associations.curriculum.store', [$this->association, $this->judo, $rank]), ['technique_item_id' => $item->id, 'required_level' => 2])->assertForbidden();
        $this->post(route('associations.techniques.template', [$this->association, $this->judo]), ['template_key' => 'judo_kodokan_2026'])->assertForbidden();
    }

    /** @return array<string, array{level: int, origin: string}> */
    private function rows(array $effective): array
    {
        return collect($effective['rows'])->mapWithKeys(fn (array $row) => [$row['item']->name => ['level' => $row['level'], 'origin' => $row['origin']]])->all();
    }

    private function item(string $name, string $kind, string $knowledge, ?TechniqueItem $parent = null): TechniqueItem
    {
        return TechniqueItem::query()->create([
            'association_id' => $this->association->id, 'martial_art_id' => $this->judo->id, 'parent_id' => $parent?->id,
            'kind' => $kind, 'knowledge_type' => $knowledge, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function context(array $codes, ?Association $association = null): array
    {
        $association ??= Association::query()->create(['legal_name' => 'Associação '.Str::random(4), 'trade_name' => 'Associação']);
        $permissions = collect($codes)->map(fn (string $code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Perfil', 'code' => 'role_'.Str::random(8), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $user];
    }
}
