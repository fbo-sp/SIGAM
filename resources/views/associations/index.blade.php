@extends('layouts.app')

@section('title', 'Associações — JUDOMA')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">Organização</span><h1>Associações</h1><p>Selecione uma associação para administrar seus dojos e pessoas.</p></div>
    @if(auth()->user()->hasPermission('association.manage', null))
        <a class="button button-primary" href="{{ route('associations.create') }}">Nova associação</a>
    @endif
</div>

<div class="card list-card">
    @forelse($associations as $association)
        <a class="list-row" href="{{ route('associations.show', $association) }}">
            <span><strong>{{ $association->trade_name }}</strong><small>{{ $association->legal_name }}</small></span>
            <span class="status-pill {{ $association->status === 'inactive' ? 'status-inactive' : '' }}">{{ $association->status === 'active' ? 'Ativa' : 'Inativa' }}</span>
        </a>
    @empty
        <div class="empty-state"><h2>Nenhuma associação cadastrada</h2><p>Cadastre a primeira associação para começar.</p></div>
    @endforelse
</div>
{{ $associations->links() }}
@endsection
