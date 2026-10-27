@extends('layouts.app')
@section('title', 'Avaliar técnicas da aula — '.$session->classGroup->name)
@section('content')
@include('partials.back-to-dashboard')
<nav class="breadcrumbs"><a href="{{ route('operation.sessions', $dojo) }}">Aulas</a><span>/</span><a href="{{ route('operation.session', [$dojo, $session]) }}">{{ $session->classGroup->name }}</a><span>/</span><span>Avaliar técnicas</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $session->session_date->format('d/m/Y') }} · {{ $session->classGroup->martialArt->name }}</span><h1>Avaliar técnicas desta aula</h1><p>Alunos presentes, nas técnicas marcadas na aula que fazem parte do currículo da próxima faixa de cada um. Marque só quem você avaliou; a data é a da aula.</p></div></div>
@if($sheet->isEmpty())
<div class="card empty-state"><p>Nenhum aluno presente tem as técnicas desta aula no currículo da próxima faixa.</p></div>
@else
<form method="post" action="{{ route('operation.session.assess.store', [$dojo, $session]) }}">@csrf
@error('levels')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('tips')<div class="alert alert-danger">{{ $message }}</div>@enderror
<p class="card level-legend assess-legend">@foreach($levels as $value => $levelLabel)<span><strong>{{ $value }}</strong> {{ $levelLabel }}</span>@endforeach</p>
@foreach($sheet as $entry)@php($itemId = $entry['item']->id)
<section class="section-block assess-group"><details class="card progress-group" @if($loop->first) open @endif><summary><strong>{{ $entry['item']->name }}</strong><span class="status-pill">{{ $entry['rows']->count() }} aluno(s)</span></summary>
<div class="list-card">@foreach($entry['rows'] as $row)@php($studentId = $row['student']->id)
<fieldset class="list-row assess-row"><legend class="sr-only">{{ $row['student']->person->social_name ?: $row['student']->person->full_name }} em {{ $entry['item']->name }}</legend><span class="person-cell"><x-person-photo :person="$row['student']->person" /><span><strong>{{ $row['student']->person->social_name ?: $row['student']->person->full_name }}</strong><small>Exige {{ $levels[$row['required']] }}@if($row['current']) · atual {{ $levels[$row['current']] }}@endif</small></span></span>
<span class="level-options">@foreach($levels as $value => $levelLabel)<label @class(['level-option', 'is-current' => $row['current'] === $value])><input type="radio" name="levels[{{ $studentId }}][{{ $itemId }}]" value="{{ $value }}" @checked((int) old("levels.$studentId.$itemId") === $value)><span title="{{ $levelLabel }}{{ $row['current'] === $value ? ' (atual)' : '' }}">{{ $value }}</span></label>@endforeach</span>
<input class="assess-tip" name="tips[{{ $studentId }}][{{ $itemId }}]" value="{{ old("tips.$studentId.$itemId") }}" maxlength="300" placeholder="Dica para o aluno (opcional)" aria-label="Dica para o aluno"></fieldset>
@endforeach</div></details></section>
@endforeach
<div class="assess-actions"><a class="button button-secondary" href="{{ route('operation.session', [$dojo, $session]) }}">Cancelar</a><button class="button button-primary">Salvar avaliação</button></div>
</form>
@endif
@endsection
