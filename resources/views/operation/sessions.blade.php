@extends('layouts.app')
@section('title', 'Aulas — '.$dojo->name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Aulas</span></nav>
<div class="page-heading"><div><span class="eyebrow">Agenda autorizada</span><h1>Aulas e chamada</h1><p>A lista já está limitada à sua unidade e, para professores, às aulas designadas.</p></div></div>
<form class="card filter-bar" method="get"><div class="field"><label for="date">Data</label><input id="date" name="date" type="date" value="{{ request('date') }}"></div><button class="button button-secondary">Filtrar</button><a class="button button-secondary" href="{{ route('operation.sessions', $dojo) }}">Limpar</a></form>
<div class="card list-card">
@forelse($sessions as $session)
    <a class="list-row" href="{{ route('operation.session', [$dojo, $session]) }}"><span><strong>{{ $session->classGroup->name }}</strong><small>{{ $session->session_date->format('d/m/Y') }} · {{ substr($session->start_time, 0, 5) }} · {{ $session->visitors_count }} visitante(s)</small></span><span class="status-pill {{ $session->attendance_state === 'open' ? 'status-inactive' : '' }}">{{ $session->status === 'cancelled' ? 'Cancelada' : ($session->attendance_state === 'closed' ? 'Chamada fechada' : 'Chamada aberta') }}</span></a>
@empty
    <div class="empty-state"><h2>Nenhuma aula disponível</h2><p>Escolha outra data ou peça ao gestor para conferir sua designação.</p></div>
@endforelse
</div>
{{ $sessions->links() }}
@endsection
