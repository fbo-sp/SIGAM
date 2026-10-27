@php
    $marked = array_flip($markedTechniques);
    $markedCounts = [];
    $countMarked = function (array $nodes) use (&$countMarked, &$markedCounts, $marked): int {
        $total = 0;
        foreach ($nodes as $node) {
            $below = $countMarked($node['children']);
            $markedCounts[$node['item']->id] = $below;
            $total += $below + (isset($marked[$node['item']->id]) ? 1 : 0);
        }
        return $total;
    };
    $countMarked($techniqueTree->all());
    $byType = $techniqueTree->groupBy(fn ($node) => $node['item']->knowledge_type);
    $markedItems = collect();
    $collect = function (array $nodes, array $path) use (&$collect, &$markedItems, $marked): void {
        foreach ($nodes as $node) {
            if (isset($marked[$node['item']->id])) { $markedItems->push(['root' => $path[0] ?? $node['item']->name, 'name' => $node['item']->name]); }
            $collect($node['children'], [...$path, $node['item']->name]);
        }
    };
    $collect($techniqueTree->all(), []);
@endphp
@if($techniqueTree->isNotEmpty() && ($canMarkTechniques || $markedTechniques !== []))
<section class="section-block" id="tecnicas"><div class="section-heading"><h2>Técnicas trabalhadas</h2><p>{{ $canMarkTechniques ? 'Marque o grupo inteiro ou abra a seta para marcar técnicas específicas. Contam para os alunos presentes.' : 'Registradas nesta aula.' }}</p></div>
@if($canMarkTechniques)
<form method="post" action="{{ route('operation.session.techniques', [$dojo, $session]) }}">@csrf
@error('techniques')<div class="alert alert-danger">{{ $message }}</div>@enderror
@include('progression._tree-toolbar', ['target' => 'aula'])
<div data-tree="aula">
@foreach(['theoretical' => 'Teórico', 'practical' => 'Prático'] as $type => $label)@if($byType->has($type))
<div class="card tree-card"><h3 class="tree-card-title">{{ $label }}</h3>@include('progression._tree', ['nodes' => $byType[$type]->all(), 'mode' => 'pick', 'root' => true])</div>
@endif @endforeach
<p class="tree-no-results" data-tree-empty hidden>Nenhuma técnica com esse nome.</p>
</div>
<div class="form-actions tree-save"><button class="button button-primary">Salvar técnicas</button></div>
</form>
@push('scripts')<script src="{{ asset('js/technique-tree.js') }}" defer></script>@endpush
@else
<div class="card list-card">@foreach($markedItems->groupBy('root') as $root => $rows)<div class="list-row"><span><strong>{{ $root }}</strong><small>{{ $rows->pluck('name')->join(', ') }}</small></span></div>@endforeach</div>
@endif
@if($assessUrl ?? null)<div class="form-actions"><a class="button button-primary" href="{{ $assessUrl }}">Avaliar técnicas desta aula</a></div>@endif
</section>
@endif
