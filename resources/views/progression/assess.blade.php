@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('title', 'Avaliar '.$name)
@section('content')
@include('partials.back-to-dashboard')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><a href="{{ route('operation.progression.show', [$dojo, $student, 'modalidade' => $martialArt->id]) }}">{{ $name }}</a><span>/</span><span>Avaliar</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · rumo a {{ $snapshot['next_rank']?->display_name }}</span><h1>Avaliar {{ $name }}</h1><p>Marque o nível só dos itens que você avaliou agora. Cada item marcado vira um registro na linha do tempo, mesmo quando o nível se mantém.</p></div></div>
@if(! $snapshot['next_rank'] || $snapshot['rows']->isEmpty())
<div class="card empty-state"><h2>Nada para avaliar</h2><p>A próxima graduação não tem currículo definido pela associação.</p></div>
@else
@php($statusLabels = \App\Services\Progression\StudentProgressService::STATUS_LABELS)
<nav class="chip-filters" aria-label="Filtrar itens"><a href="{{ route('operation.progression.assess', [$dojo, $student, $martialArt]) }}" @if(! $filter) aria-current="page" @endif>Todos</a>@foreach(['awaiting', 'improve', 'untrained'] as $status)<a href="{{ route('operation.progression.assess', [$dojo, $student, $martialArt, 'situacao' => $status]) }}" @if($filter === $status) aria-current="page" @endif>{{ $statusLabels[$status] }} ({{ $snapshot['statuses'][$status] }})</a>@endforeach</nav>
<form method="post" action="{{ route('operation.progression.assess.store', [$dojo, $student, $martialArt]) }}">@csrf
@error('levels')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('tips')<div class="alert alert-danger">{{ $message }}</div>@enderror
<div class="card form-grid assess-header"><p class="field-wide level-legend">@foreach($levels as $value => $levelLabel)<span><strong>{{ $value }}</strong> {{ $levelLabel }}</span>@endforeach</p>
<div class="field"><label for="assessed_on">Data da avaliação</label><input id="assessed_on" type="date" name="assessed_on" value="{{ old('assessed_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required>@error('assessed_on')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="notes">Observação (opcional)</label><input id="notes" name="notes" value="{{ old('notes') }}" maxlength="1000"></div></div>
@foreach($knowledgeLabels as $type => $label)
@if($snapshot['rows']->has($type))
@foreach($snapshot['rows'][$type]->when($filter, fn ($rows) => $rows->where('status', $filter))->groupBy(fn ($row) => \Illuminate\Support\Str::beforeLast($row['path'], ' > ')) as $group => $rows)
<section class="section-block assess-group"><div class="section-heading"><h2>{{ $group }}</h2><p>{{ $label }}</p></div><div class="card list-card">
@foreach($rows as $row)@php($id = $row['item']->id)
<fieldset class="list-row assess-row"><legend class="sr-only">{{ $row['item']->name }}</legend><span><strong>{{ $row['item']->name }}</strong><small>Exige {{ $levels[$row['level']] }}@if($row['current']) · atual {{ $levels[$row['current']] }}@if($row['assessed_on']) em {{ $row['assessed_on']->format('d/m') }}@endif @endif</small>@if($row['status'] && $row['status'] !== 'ok')<small @class(['progress-status', 'is-'.$row['status']])>{{ $statusLabels[$row['status']] }}@if($row['trained_on']) · treinado em {{ $row['trained_on']->format('d/m') }}@endif</small>@endif</span>
<span class="level-options">@foreach($levels as $value => $levelLabel)<label @class(['level-option', 'is-current' => $row['current'] === $value])><input type="radio" name="levels[{{ $id }}]" value="{{ $value }}" @checked((int) old("levels.$id") === $value)><span title="{{ $levelLabel }}{{ $row['current'] === $value ? ' (atual)' : '' }}">{{ $value }}</span></label>@endforeach</span>
<input class="assess-tip" name="tips[{{ $id }}]" value="{{ old("tips.$id") }}" maxlength="300" placeholder="Dica para o aluno (opcional)" aria-label="Dica para o aluno sobre {{ $row['item']->name }}"></fieldset>
@endforeach</div></section>
@endforeach
@endif
@endforeach
<div class="assess-actions"><a class="button button-secondary" href="{{ route('operation.progression.show', [$dojo, $student, 'modalidade' => $martialArt->id]) }}">Cancelar</a><button class="button button-primary">Salvar avaliação</button></div>
</form>
@endif
@endsection
