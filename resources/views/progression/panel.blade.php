@extends('layouts.app')
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('title', 'Acompanhamento técnico — '.$dojo->name)
@section('content')
@include('partials.back-to-dashboard')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Acompanhamento técnico</span></nav>
<div class="page-heading"><div><span class="eyebrow">Painel do dojo</span><h1>Acompanhamento técnico</h1><p>O que as turmas precisam para a próxima graduação e o que foi trabalhado nas aulas.</p></div></div>

@if($martialArts->isEmpty())
<div class="card empty-state"><h2>Nenhuma turma no painel</h2><p>O painel mostra as turmas ativas que você acompanha neste dojo.</p></div>
@else
<form class="card filter-form" method="get" action="{{ route('operation.progression.panel', $dojo) }}">
@if($martialArts->count() > 1)<div class="field"><label for="modalidade">Modalidade</label><select id="modalidade" name="modalidade">@foreach($martialArts as $option)<option value="{{ $option->id }}" @selected($option->id === $martialArt->id)>{{ $option->name }}</option>@endforeach</select></div>@endif
<div class="field"><label for="turma">Turma</label><select id="turma" name="turma"><option value="">Todas ({{ $classes->count() }})</option>@foreach($classes as $option)<option value="{{ $option->id }}" @selected($selected?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
<div class="field"><label for="de">De</label><input id="de" name="de" type="date" value="{{ $from->toDateString() }}"></div>
<div class="field"><label for="ate">Até</label><input id="ate" name="ate" type="date" value="{{ $until->toDateString() }}">@error('ate')<p class="field-error">{{ $message }}</p>@enderror</div>
<button class="button button-secondary">Filtrar</button></form>

<div class="portal-metrics panel-metrics">
<article class="card"><span>Alunos</span><strong>{{ $panel['students'] }}</strong></article>
<article class="card"><span>Aulas no período</span><strong>{{ $panel['sessions'] }}</strong></article>
<article class="card"><span>Aulas com técnicas registradas</span><strong>{{ $panel['sessions_with_techniques'] }}</strong></article>
<article class="card"><span>Prontos no currículo</span><strong>{{ $panel['ranking']->filter(fn ($row) => $row['next_rank'] && $row['theoretical'] + $row['practical'] === 0)->count() }}</strong></article>
</div>

@if($panel['axes']->isEmpty())
<div class="card empty-state"><h2>Sem currículo para comparar</h2><p>Os alunos ainda não têm exigências de currículo na próxima graduação.</p></div>
@else
@foreach($knowledgeLabels as $type => $label)@if($panel['axes']->has($type))
<section class="section-block"><div class="section-heading"><h2>{{ $label }}</h2><p>Média do nível atual dos alunos por grupo, comparada com o exigido pela próxima graduação de cada um.</p></div>
<div class="radar-grid"><article class="card radar-card"><x-radar :axes="$panel['axes'][$type]" :label="'Perfil da turma: '.$label" /></article>
<div class="card list-card"><div class="list-row"><span><strong>Precisa x trabalhado</strong><small>Alunos abaixo do exigido e aulas em que o grupo foi trabalhado ({{ $panel['sessions'] }} aula(s) no período)</small></span></div>
@foreach($panel['axes'][$type] as $axis)<div class="list-row progress-row"><span><strong>{{ $axis['label'] }}</strong><small>{{ $axis['students_below'] }} de {{ $axis['students'] }} aluno(s) abaixo do exigido</small></span><span class="level-gap"><span class="status-pill {{ $axis['students_below'] > 0 && $axis['sessions_worked'] === 0 ? 'status-inactive' : '' }}">{{ $axis['sessions_worked'] }} aula(s)</span></span></div>@endforeach
</div></div></section>
@endif @endforeach
@endif

<section class="section-block"><div class="section-heading"><h2>Alunos com mais itens a trabalhar</h2><p>Quantidade de itens abaixo do nível exigido pela próxima graduação.</p></div>
<div class="card list-card">@forelse($panel['ranking']->take(20) as $row)<div class="list-row progress-row"><span><strong><a href="{{ route('operation.progression.show', [$dojo, $row['student'], 'modalidade' => $martialArt->id]) }}">{{ $row['student']->person->social_name ?: $row['student']->person->full_name }}</a></strong><small>{{ $row['next_rank'] ? 'Rumo a '.$row['next_rank']->display_name : 'Última graduação' }}</small></span><span class="level-gap"><span class="status-pill {{ $row['theoretical'] ? 'status-inactive' : '' }}">Teórico: {{ $row['theoretical'] }}</span><span class="status-pill {{ $row['practical'] ? 'status-inactive' : '' }}">Prático: {{ $row['practical'] }}</span></span></div>@empty<div class="empty-state"><p>Nenhum aluno matriculado nas turmas.</p></div>@endforelse</div>
</section>
@endif
@endsection
