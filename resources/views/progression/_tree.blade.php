{{-- Nó da árvore do catálogo. $mode: catalog (consulta e opções) ou pick (marcar técnicas da aula). --}}
<ul class="tree {{ $root ?? false ? 'tree-root' : '' }}">
@foreach($nodes as $node)@php($item = $node['item'])
@if($mode === 'pick')
@php($groupCheck = $item->kind !== 'group' || ($allowGroupPick ?? true) || isset($marked[$item->id]))
<li class="tree-pick" data-tree-node data-name="{{ mb_strtolower($item->name.' '.$item->original_name) }}">@if($groupCheck)<input type="checkbox" class="tree-check" name="techniques[]" value="{{ $item->id }}" id="tecnica-{{ $item->id }}" @checked(isset($marked[$item->id]))>@else<span class="tree-check-spacer" aria-hidden="true"></span>@endif
@if($item->kind === 'group')
<details class="tree-group" @if(($markedCounts[$item->id] ?? 0) > 0 || isset($marked[$item->id]) || ($root ?? false)) open @endif><summary><span class="tree-caret" aria-hidden="true"></span>@if($groupCheck)<label class="tree-label" for="tecnica-{{ $item->id }}" title="Marcar o grupo inteiro">{{ $item->name }}</label>@else<span class="tree-label">{{ $item->name }}</span>@endif @if(isset($marked[$item->id]) && ! ($allowGroupPick ?? true))<span class="tree-count is-marked">grupo inteiro</span>@endif @if(($markedCounts[$item->id] ?? 0) > 0)<span class="tree-count is-marked">{{ $markedCounts[$item->id] }} marcada(s)</span>@elseif($node['count'] > 0)<span class="tree-count">{{ $node['count'] }}</span>@endif</summary>
@include('progression._tree', ['nodes' => $node['children'], 'root' => false])</details>
@else
<label class="tree-leaf" for="tecnica-{{ $item->id }}"><span class="tree-label">{{ $item->name }}</span></label>
@endif
</li>
@else
@php($isInactive = isset($inactive[$item->id]))
<li data-tree-node data-name="{{ mb_strtolower($item->name.' '.$item->original_name) }}">
@php($menu = $canManage ? view('progression._tree-menu', compact('association', 'martialArt', 'item'))->render() : '')
@if($item->kind === 'group')
<details class="tree-group"><summary @class(['is-inactive' => $isInactive])><span class="tree-caret" aria-hidden="true"></span><span class="tree-label">{{ $item->name }}@if($item->original_name)<small>{{ $item->original_name }}</small>@endif</span>@if($isInactive)<span class="status-pill status-inactive">Inativo</span>@endif<span class="tree-count" title="Técnicas dentro do grupo">{{ $node['count'] }}</span>{!! $menu !!}</summary>
@if($node['children'] !== [])@include('progression._tree', ['nodes' => $node['children'], 'root' => false])@else<p class="tree-empty">Grupo vazio.</p>@endif</details>
@else
<div @class(['tree-leaf', 'is-inactive' => $isInactive])><span class="tree-dot" aria-hidden="true"></span><span class="tree-label">{{ $item->name }}@if($item->original_name || $item->video_url)<small>{{ $item->original_name }}@if($item->original_name && $item->video_url) · @endif @if($item->video_url)<a href="{{ $item->video_url }}" target="_blank" rel="noopener noreferrer">vídeo</a>@endif</small>@endif</span>@if($isInactive)<span class="status-pill status-inactive">Inativo</span>@endif{!! $menu !!}</div>
@endif
</li>
@endif
@endforeach
</ul>
