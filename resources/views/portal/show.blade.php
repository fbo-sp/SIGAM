@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($weekdays = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'])
@section('title', 'Portal de '.$name.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header')
<section class="portal-hello">
<x-person-photo :person="$student->person" size="medium" />
<div><span class="eyebrow">{{ $student->person_id === auth()->user()->person_id ? 'Meu portal' : 'Dependente' }}</span><h1>Olá, {{ \Illuminate\Support\Str::of($name)->explode(' ')->first() }}</h1>
<p>@foreach($student->martialArts as $practice){{ $practice->martialArt->name }} · {{ $practice->currentRank?->display_name ?: 'sem graduação' }} · @endforeach{{ $student->primaryDojo?->name ?: 'Dojo não informado' }}</p></div>
</section>

<section class="portal-indicators" aria-label="Indicadores">
<a class="card portal-indicator" href="{{ route('portal.students.section', [$student, 'turmas']) }}"><span>Próxima aula</span>@if($indicators['next_session'])<strong>{{ ucfirst($weekdays[$indicators['next_session']->session_date->dayOfWeek]) }} {{ $indicators['next_session']->session_date->format('d/m') }}, {{ substr($indicators['next_session']->start_time, 0, 5) }}</strong><small>{{ $indicators['next_session']->classGroup->name }}</small>@else<strong>Sem aula prevista</strong><small>Veja turmas e horários</small>@endif</a>
<a class="card portal-indicator" href="{{ route('portal.students.section', [$student, 'frequencia']) }}"><span>Frequência no mês</span>@if($indicators['attendance']['percent'] !== null)<strong>{{ $indicators['attendance']['percent'] }}%</strong><small>{{ $indicators['attendance']['present'] }} de {{ $indicators['attendance']['total'] }} aula(s)</small>@else<strong>—</strong><small>Nenhuma chamada no mês</small>@endif</a>
<a class="card portal-indicator {{ $indicators['notices']['count'] ? 'is-alert' : '' }}" href="{{ route('notifications.index') }}"><span>Avisos</span>@if($indicators['notices']['count'])<strong>{{ $indicators['notices']['count'] === 1 ? '1 novo' : $indicators['notices']['count'].' novos' }}</strong><small>{{ \Illuminate\Support\Str::limit($indicators['notices']['latest']?->subject ?: 'Veja na caixa de avisos', 60) }}</small>@else<strong>Nada novo</strong><small>Nenhum aviso sem ler</small>@endif</a>
@if($indicators['finance']['fee'])<a class="card portal-indicator {{ $indicators['finance']['overdue'] ? 'is-warning' : '' }}" href="{{ route('portal.students.section', [$student, 'financeiro']) }}"><span>Mensalidade</span>@if($indicators['finance']['count'] === 0)<strong class="is-good">Em dia</strong><small>Nada em aberto</small>@else<strong>R$ {{ \App\Support\Money\MoneyFormatter::brl($indicators['finance']['balance']) }}</strong><small>{{ $indicators['finance']['overdue'] ? $indicators['finance']['overdue'].' vencida(s)' : $indicators['finance']['count'].' em aberto, a vencer' }}</small>@endif</a>@endif
@if($student->martialArts->isNotEmpty())<a class="card portal-indicator {{ $indicators['quiz'] ? 'is-highlight' : '' }}" href="{{ route('portal.progress.part', [$student, 'quiz']) }}"><span>Quiz</span>@if($indicators['quiz'])<strong>Prova liberada</strong><small>{{ $indicators['quiz']->rank->display_name }}</small>@else<strong>Treinar</strong><small>Prova oficial ainda não liberada</small>@endif</a>@endif
</section>

<section class="section-block"><h2 class="sr-only">Atalhos</h2>@include('portal.partials.shortcuts')</section>
@include('portal.partials.tabbar', ['current' => 'inicio'])
</div>
@endsection
