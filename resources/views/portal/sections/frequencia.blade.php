@extends('layouts.app')
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Últimos '.$attendanceSummary['total'].' registros de chamada.'])
<div class="portal-metrics"><article class="card"><span>Presente</span><strong>{{ $attendanceSummary['present'] }}</strong></article><article class="card"><span>Ausente</span><strong>{{ $attendanceSummary['absent'] }}</strong></article><article class="card"><span>Justificada</span><strong>{{ $attendanceSummary['justified'] }}</strong></article></div>
<div class="portal-list section-block">@forelse($attendance as $record)<article class="card portal-row"><div><span class="status-pill {{ $record->status === 'absent' ? 'status-inactive' : '' }}">{{ ['present' => 'Presente', 'absent' => 'Ausente', 'excused_absence' => 'Falta justificada', 'makeup' => 'Reposição', 'trial' => 'Experimental'][$record->status] ?? $record->status }}</span><h3>{{ $record->session->classGroup->name }}</h3><p>{{ $record->session->session_date->format('d/m/Y') }} · {{ $record->participation_type === 'visitor' ? 'Visita' : 'Matrícula regular' }}</p></div></article>@empty<div class="card empty-state"><p>Nenhuma chamada registrada ainda.</p></div>@endforelse</div>
@include('portal.partials.tabbar', ['current' => 'frequencia'])
</div>
@endsection
