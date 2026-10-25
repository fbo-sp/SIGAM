@extends('layouts.app')
@section('title', 'Turmas — JUDOMA')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><span>Turmas</span></nav>
<div class="page-heading"><div><span class="eyebrow">Operação esportiva</span><h1>Turmas</h1><p>Programações recorrentes, professores e matrículas vigentes.</p></div>@if(auth()->user()->hasPermission('class.manage', $association->id))<a class="button button-primary" href="{{ route('associations.classes.create', $association) }}">Nova turma</a>@endif</div>
<div class="card list-card">@forelse($groups as $group)<a class="list-row" href="{{ route('associations.classes.show', [$association, $group]) }}"><span><strong>{{ $group->name }}</strong><small>{{ $group->dojo->name }} · {{ $group->martialArt->name }} · {{ $group->enrollments_count }} matriculado(s)</small>@include('classes.partials.teacher-chips', ['group' => $group])</span><span class="status-pill {{ $group->status !== 'active' ? 'status-inactive' : '' }}">{{ $group->status === 'active' ? 'Ativa' : 'Inativa' }}</span></a>@empty<div class="empty-state"><h2>Nenhuma turma cadastrada</h2><p>Crie a primeira programação recorrente.</p></div>@endforelse</div>{{ $groups->links() }}
@endsection
