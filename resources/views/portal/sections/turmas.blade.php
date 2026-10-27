@extends('layouts.app')
@php($weekdayNames = [0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'])
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Vínculos atuais e anteriores.'])
<div class="portal-list">@forelse($student->enrollments as $enrollment)<article class="card portal-row"><div><span class="status-pill {{ $enrollment->status === 'active' ? '' : 'status-inactive' }}">{{ $enrollment->status === 'active' ? 'Ativa' : 'Encerrada' }}</span><h3>{{ $enrollment->classGroup->name }}</h3><p>{{ $enrollment->classGroup->martialArt->name }} · {{ $enrollment->classGroup->dojo->name }}</p></div><ul>@forelse($enrollment->classGroup->schedules->where('status', 'active') as $schedule)<li>{{ $weekdayNames[$schedule->weekday] ?? 'Dia '.$schedule->weekday }}, {{ substr($schedule->start_time, 0, 5) }}–{{ substr($schedule->end_time, 0, 5) }}</li>@empty<li>Horário não informado.</li>@endforelse</ul></article>@empty<div class="card empty-state"><p>Nenhuma turma encontrada.</p></div>@endforelse</div>
@include('portal.partials.tabbar', ['current' => 'turmas'])
</div>
@endsection
