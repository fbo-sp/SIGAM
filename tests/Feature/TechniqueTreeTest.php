<?php

namespace Tests\Feature;

use App\Services\Progression\TechniqueCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class TechniqueTreeTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_tree_nests_items_and_counts_techniques_below_each_group(): void
    {
        $service = app(TechniqueCatalogService::class);
        $tree = collect($service->tree($service->items($this->association, $this->art)))->keyBy(fn ($node) => $node['item']->name);

        $this->assertSame(['Kata', 'Katame-waza', 'Nage-waza', 'Teoria'], $tree->keys()->all());
        $this->assertSame(2, $tree['Teoria']['count']);
        $this->assertSame(['História', 'Regras'], collect($tree['Teoria']['children'])->map(fn ($node) => $node['item']->name)->all());
        $this->assertSame('Jigoro Kano', $tree['Teoria']['children'][0]['children'][0]['item']->name);
        $this->assertSame(1, $tree['Nage-waza']['count']);
    }

    public function test_catalog_shows_tree_with_options_only_for_managers_and_include_inside_preselects_group(): void
    {
        $url = route('associations.techniques.index', [$this->association, $this->art]);
        $history = $this->items['kano']->parent_id;
        $this->items['shido']->update(['status' => 'inactive']);

        $this->actingAs($this->associationUser(['martial_art.view', 'curriculum.manage']))->get($url)->assertOk()
            ->assertSee('class="tree tree-root"', false)->assertSee('Expandir tudo')->assertSee('Opções de Jigoro Kano')
            ->assertSee(route('associations.techniques.index', [$this->association, $this->art, 'dentro' => $history]), false)
            ->assertSee('Inativo');
        $page = $this->get($url.'?dentro='.$history)->assertOk()->assertSee('<option value="'.$history.'" selected', false)->getContent();
        $this->assertMatchesRegularExpression('/id="incluir"\s+open/', $page);

        $this->actingAs($this->associationUser(['martial_art.view']))->get($url)->assertOk()
            ->assertSee('Jigoro Kano')->assertDontSee('Opções de Jigoro Kano')->assertDontSee('Incluir grupo ou item');
    }
}
