@extends('layouts.app')
@php($weekdays = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'])
@section('title', 'Minhas turmas — JUDOMA')
@section('content')
<div class="page-heading"><div><span class="eyebrow">Professor</span><h1>Minhas turmas</h1><p>Turmas em que você é principal ou auxiliar, de todos os dojos.</p></div></div>
<div class="teacher-classes">
@forelse($classes as $item)
@php($class = $item['class'])
<article class="card teacher-class">
<div class="teacher-class-head"><span><strong>@if($item['url'])<a href="{{ $item['url'] }}">{{ $class->name }}</a>@else{{ $class->name }}@endif</strong><small>{{ $item['dojo']?->name }} · {{ $class->martialArt?->name }} · {{ $class->enrollments_count }} aluno(s) · {{ mb_strtolower($item['role']) }}</small></span>@if($item['url'])<a class="button button-secondary" href="{{ $item['url'] }}">Abrir</a>@endif</div>
<p class="teacher-class-schedule">{{ $class->schedules->map(fn ($schedule) => $weekdays[$schedule->weekday].' '.substr($schedule->start_time, 0, 5))->unique()->implode(' · ') ?: 'Sem horário cadastrado' }}</p>
@if($item['coverage'])<a class="teacher-class-coverage" href="{{ $item['coverage_url'] }}"><span>Currículo nos últimos 90 dias</span>@if($item['coverage']['total'])<x-coverage-bar :percent="$item['coverage']['percent']" :tone="$item['coverage']['tone']" :label="'Cobertura de '.$class->name" />@else<small>Sem currículo para comparar</small>@endif</a>@endif
@unless($item['url'])<p class="form-help">Peça ao gestor acesso de professor ao dojo para abrir esta turma.</p>@endunless
</article>
@empty
<div class="card empty-state"><h2>Nenhuma turma ativa</h2><p>Quando o gestor designar você numa turma, ela aparece aqui.</p></div>
@endforelse
</div>
@endsection
