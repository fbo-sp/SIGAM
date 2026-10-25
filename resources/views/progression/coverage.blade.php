@extends('layouts.app')
@section('title', 'Cobertura do currículo — '.$classGroup->name)
@section('content')
@include('partials.back-to-dashboard')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><a href="{{ route('operation.classes.show', [$dojo, $classGroup]) }}">{{ $classGroup->name }}</a><span>/</span><span>Cobertura do currículo</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $classGroup->martialArt->name }} · {{ $from->format('d/m/Y') }} a {{ $until->format('d/m/Y') }}</span><h1>Cobertura do currículo</h1><p>Quanto do currículo da próxima faixa dos alunos já foi marcado em alguma aula da turma no período.</p></div></div>
<form class="card filter-form" method="get" action="{{ route('operation.classes.coverage', [$dojo, $classGroup]) }}">
<div class="field"><label for="de">De</label><input id="de" name="de" type="date" value="{{ $from->toDateString() }}"></div>
<div class="field"><label for="ate">Até</label><input id="ate" name="ate" type="date" value="{{ $until->toDateString() }}">@error('ate')<p class="field-error">{{ $message }}</p>@enderror</div>
<button class="button button-secondary">Filtrar</button></form>

<div class="portal-metrics panel-metrics">
<article class="card"><span>Alunos</span><strong>{{ $coverage['students'] }}</strong></article>
<article class="card"><span>Aulas no período</span><strong>{{ $coverage['sessions'] }}</strong></article>
<article class="card"><span>Aulas com técnicas marcadas</span><strong>{{ $coverage['sessions_with_techniques'] }}</strong></article>
<article class="card"><span>Faixas buscadas</span><strong>{{ $coverage['ranks']->count() }}</strong></article>
</div>

@if($coverage['overall']['total'] === 0)
<div class="card empty-state"><h2>Sem currículo para comparar</h2><p>{{ $coverage['students'] ? 'A próxima faixa dos alunos ainda não tem itens no currículo.' : 'A turma não tem alunos matriculados.' }}</p></div>
@else
<section class="section-block"><div class="section-heading"><h2>Visão geral da turma</h2><p>Todas as faixas buscadas juntas: {{ $coverage['overall']['worked'] }} de {{ $coverage['overall']['total'] }} itens já trabalhados.</p></div>
<div class="card list-card"><div class="list-row coverage-row"><span><strong>Turma inteira</strong><small>Verde a partir de 70%, amarelo de 40% a 69%, vermelho abaixo de 40%.</small></span><x-coverage-bar :percent="$coverage['overall']['percent']" :tone="$coverage['overall']['tone']" label="Turma inteira" /></div>
@include('progression._coverage-detail', ['summary' => $coverage['overall']])</div></section>

<section class="section-block"><div class="section-heading"><h2>Por faixa</h2><p>Uma linha por faixa que os alunos estão buscando. Toque para ver os grupos, o que falta e os alunos.</p></div>
@foreach($coverage['ranks'] as $row)
<details class="card coverage-rank" @if($loop->first && $coverage['ranks']->count() === 1) open @endif><summary><span><strong>Rumo a {{ $row['rank']->display_name }} · {{ $row['students']->count() }} aluno(s)</strong><small>{{ $row['total'] ? $row['worked'].' de '.$row['total'].' itens já trabalhados' : 'Sem itens no currículo desta faixa' }}</small></span><x-coverage-bar :percent="$row['percent']" :tone="$row['tone']" :label="'Rumo a '.$row['rank']->display_name" /></summary>
@if($row['total'])@include('progression._coverage-detail', ['summary' => $row])@endif
<div class="coverage-detail"><div><h3>Alunos desta faixa</h3><ul class="coverage-students">@foreach($row['students'] as $entry)<li><x-person-photo :person="$entry['student']->person" /><a href="{{ route('operation.progression.show', [$dojo, $entry['student'], 'modalidade' => $classGroup->martial_art_id]) }}">{{ $entry['student']->person->social_name ?: $entry['student']->person->full_name }}</a><small>{{ $entry['current_rank']?->display_name ?? 'sem faixa' }}</small></li>@endforeach</ul></div></div>
</details>
@endforeach
</section>
@endif
@if($coverage['without_next']->isNotEmpty())<p class="form-help">{{ $coverage['without_next']->count() }} aluno(s) já estão na última faixa e não entram na cobertura.</p>@endif
@endsection
