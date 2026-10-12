@extends('layouts.app')
@section('title', 'Campeonatos — JUDOMA')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Campeonatos</span></nav>
<div class="page-heading"><div><span class="eyebrow">Operação do dojo</span><h1>Campeonatos da associação</h1><p>O evento e suas regras são mantidos pela associação. Aqui aparecem somente as inscrições desta unidade.</p></div></div>
<div class="card list-card">@forelse($championships as $championship)<div class="list-row"><span><strong>{{ $championship->name }}</strong><small>{{ $championship->martialArt->name }} · {{ $championship->start_date->format('d/m/Y') }} · {{ $championship->local_registrations_count }} inscrição(ões) deste dojo</small></span><a class="button button-secondary" href="{{ route('operation.championships.show', [$dojo, $championship]) }}">Ver evento</a></div>@empty<div class="empty-state"><p>Nenhum campeonato publicado pela associação.</p></div>@endforelse</div>{{ $championships->links() }}
@endsection
