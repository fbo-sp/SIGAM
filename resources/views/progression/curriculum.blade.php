@extends('layouts.app')
@section('title', 'Currículo — '.$martialArt->name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('content')
@include('progression._tabs', ['current' => 'curriculum', 'title' => 'Currículo por graduação', 'subtitle' => 'O que é cobrado no exame de cada graduação. Cada graduação herda as exigências das anteriores.'])

@if($ranks->isEmpty())
<div class="card empty-state"><h2>Nenhuma graduação cadastrada</h2><p>Cadastre as graduações da modalidade antes de montar o currículo.</p></div>
@else
<form class="card filter-form rank-picker" method="get" action="{{ route('associations.curriculum.show', [$association, $martialArt]) }}"><div class="field"><label for="graduacao">Graduação</label><select id="graduacao" name="graduacao">@foreach($ranks as $option)<option value="{{ $option->id }}" @selected($option->id === $rank->id)>{{ $option->display_name }}</option>@endforeach</select></div><button class="button button-secondary">Ver currículo</button></form>
<div class="section-heading"><h2>Para chegar a {{ $rank->display_name }}</h2><p>{{ $rows->flatten(1)->count() }} item(ns) cobrado(s). Os marcados como herdados vêm das graduações anteriores.</p></div>

@if($canManage)
<details class="card disclosure" @if($errors->hasAny(['technique_item_id','required_level'])) open @endif><summary>Incluir ou alterar exigência nesta graduação</summary><form class="form-grid" method="post" action="{{ route('associations.curriculum.store', [$association, $martialArt, $rank]) }}">@csrf
<div class="field field-wide"><label for="technique_item_id">Item do catálogo</label><select id="technique_item_id" name="technique_item_id" required><option value="">Selecione</option>@foreach($options as $id => $path)<option value="{{ $id }}" @selected((int) old('technique_item_id') === $id)>{{ $path }}</option>@endforeach</select>@error('technique_item_id')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="required_level">Nível exigido</label><select id="required_level" name="required_level">@foreach($levels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach<option value="0">Não cobrar a partir desta graduação</option></select>@error('required_level')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="form-actions field-wide"><button class="button button-primary">Salvar exigência</button></div></form></details>
@endif

@foreach($knowledgeLabels as $type => $label)
@if($rows->has($type))
<section class="section-block"><div class="section-heading"><h2>{{ $label }}</h2></div><div class="card list-card">
@foreach($rows[$type] as $row)@php($parentPath = \Illuminate\Support\Str::contains($row['path'], ' > ') ? \Illuminate\Support\Str::beforeLast($row['path'], ' > ') : '')
<div class="list-row"><span><strong>{{ $row['item']->name }}</strong><small>@if($parentPath !== ''){{ $parentPath }} · @endif
@if($row['origin'] === 'new')Novo nesta graduação@elseif($row['origin'] === 'raised')Nível alterado nesta graduação (antes {{ \App\Support\Progression\AssessmentLevel::label($row['previous_level']) }})@else Herdado de {{ $row['rank']->display_name }}@endif</small></span>
<div class="row-actions"><span class="status-pill {{ $row['origin'] === 'inherited' ? 'status-inactive' : '' }}">{{ \App\Support\Progression\AssessmentLevel::label($row['level']) }}</span>
@if($canManage)<details class="row-action"><summary>Alterar</summary><form class="form-stack" method="post" action="{{ route('associations.curriculum.store', [$association, $martialArt, $rank]) }}">@csrf<input type="hidden" name="technique_item_id" value="{{ $row['item']->id }}"><label class="sr-only" for="level-{{ $row['item']->id }}">Nível exigido</label><select id="level-{{ $row['item']->id }}" name="required_level">@foreach($levels as $value => $levelLabel)<option value="{{ $value }}" @selected($row['level'] === $value)>{{ $levelLabel }}</option>@endforeach<option value="0">Não cobrar a partir desta graduação</option></select><button class="button button-primary">Salvar</button></form>
@if($own->has($row['item']->id))<form method="post" action="{{ route('associations.curriculum.restore', [$association, $martialArt, $rank, $row['item']]) }}">@csrf<button class="button button-secondary">{{ $row['origin'] === 'new' ? 'Retirar desta graduação' : 'Desfazer ajuste' }}</button></form>@endif
</details>@endif</div></div>
@endforeach</div></section>
@endif
@endforeach

@if($rows->isEmpty() && $removed === [])
<div class="card empty-state"><h2>Nenhuma exigência nesta graduação</h2><p>{{ $canManage ? 'Inclua itens do catálogo ou aplique o modelo na aba Catálogo de técnicas.' : 'A associação ainda não definiu o currículo desta graduação.' }}</p></div>
@endif

@if($removed !== [])
<section class="section-block"><div class="section-heading"><h2>Deixam de ser cobrados nesta graduação</h2></div><div class="card list-card">
@foreach($removed as $row)<div class="list-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ $row['path'] }} · antes {{ \App\Support\Progression\AssessmentLevel::label($row['previous_level']) }}</small></span>
@if($canManage)<form method="post" action="{{ route('associations.curriculum.restore', [$association, $martialArt, $rank, $row['item']]) }}">@csrf<button class="button button-secondary">Voltar a cobrar</button></form>@endif</div>
@endforeach</div></section>
@endif
@endif
@endsection
