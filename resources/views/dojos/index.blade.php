@extends('layouts.app')
@section('title', 'Dojos — JUDOMA')
@section('content')
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><span>Dojos</span></nav>
<div class="page-heading"><div><span class="eyebrow">Unidades</span><h1>Dojos</h1><p>Consulte e mantenha as unidades da associação.</p></div>@if(auth()->user()->hasPermission('dojo.manage', $association->id))<a class="button button-primary" href="{{ route('associations.dojos.create', $association) }}">Novo dojo</a>@endif</div>
<form class="card filter-bar" method="get"><div class="field"><label for="q">Buscar</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Nome ou código"></div><div class="field"><label for="status">Situação</label><select id="status" name="status"><option value="">Todas</option><option value="active" @selected(request('status') === 'active')>Ativos</option><option value="inactive" @selected(request('status') === 'inactive')>Inativos</option></select></div><button class="button button-secondary" type="submit">Filtrar</button></form>
<div class="card list-card">@forelse($dojos as $dojo)<a class="list-row" href="{{ route('associations.dojos.show', [$association, $dojo]) }}"><span><strong>{{ $dojo->name }}</strong><small>Código {{ $dojo->code }}@if($dojo->city) · {{ $dojo->city }}/{{ $dojo->state }}@endif</small></span><span class="status-pill {{ $dojo->status === 'inactive' ? 'status-inactive' : '' }}">{{ $dojo->status === 'active' ? 'Ativo' : 'Inativo' }}</span></a>@empty<div class="empty-state"><h2>Nenhum dojo encontrado</h2><p>Altere os filtros ou cadastre uma unidade.</p></div>@endforelse</div>
{{ $dojos->links() }}
@endsection
