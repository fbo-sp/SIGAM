@extends('layouts.app')
@section('title', 'Aniversariantes — JUDOMA')
@section('content')
@php($monthName = fn ($date) => $date->locale('pt_BR')->translatedFormat('F'))
@if($context->level === 'association')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $context->association) }}">{{ $context->association->trade_name }}</a><span>/</span><span>Aniversariantes</span></nav>
@else
@include('partials.back-to-dashboard')
@endif
<div class="page-heading"><div><span class="eyebrow">{{ $context->level === 'dojo' ? $context->dojo->name : ($context->isTeacher() ? 'Professor' : $context->association->trade_name) }}</span><h1>Aniversariantes</h1><p>{{ $context->isTeacher() ? 'Alunos das suas turmas e os professores delas, por dia do mês.' : 'Alunos ativos e professores, por dia do mês.' }}</p></div></div>
<form class="card filter-bar birthday-filters" method="get" action="{{ $action }}">
<div class="field"><label for="mes">Mês</label><select id="mes" name="mes">@foreach($months as $option)<option value="{{ $option->format('Y-m') }}" @selected($option->isSameMonth($month))>{{ ucfirst($monthName($option)) }} de {{ $option->year }}</option>@endforeach</select></div>
@if($context->level === 'association')<div class="field"><label for="dojo">Dojo</label><select id="dojo" name="dojo"><option value="">Todos</option>@foreach($context->dojos as $dojo)<option value="{{ $dojo->id }}" @selected($filters['dojo_id'] === $dojo->id)>{{ $dojo->name }}</option>@endforeach</select></div>@endif
<div class="field"><label for="turma">Turma</label><select id="turma" name="turma"><option value="">Todas</option>@foreach($context->classes as $group)<option value="{{ $group->id }}" @selected($filters['class_group_id'] === $group->id)>{{ $group->name }}{{ $context->level === 'association' || $context->dojos->count() > 1 ? ' · '.$group->dojo?->name : '' }}</option>@endforeach</select></div>
<div class="field"><label for="mostrar">Mostrar</label><select id="mostrar" name="mostrar">@foreach(\App\Services\People\BirthdayService::SHOW as $value => $label)<option value="{{ $value }}" @selected($filters['show'] === $value)>{{ $label }}</option>@endforeach</select></div>
<button class="button button-secondary" type="submit">Filtrar</button>
</form>
@php($previous = $month->copy()->subMonth())
@php($next = $month->copy()->addMonth())
@php($query = array_filter(['dojo' => $filters['dojo_id'], 'turma' => $filters['class_group_id'], 'mostrar' => $filters['show'] !== 'todos' ? $filters['show'] : null]))
<nav class="birthday-month-nav" aria-label="Outros meses"><a href="{{ $action.'?'.http_build_query(['mes' => $previous->format('Y-m')] + $query) }}">← {{ $monthName($previous) }}</a><span>{{ $rows->count() }} aniversariante(s) em {{ $monthName($month) }}{{ $rows->where('today', true)->count() ? ' · '.$rows->where('today', true)->count().' hoje' : '' }}</span><a href="{{ $action.'?'.http_build_query(['mes' => $next->format('Y-m')] + $query) }}">{{ $monthName($next) }} →</a></nav>
@include('birthdays.partials.list', ['rows' => $rows])
@endsection
