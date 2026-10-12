@extends('layouts.app')
@section('title', 'Turmas — '.$dojo->name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Turmas</span></nav>
<div class="page-heading"><div><span class="eyebrow">Organização das aulas</span><h1>Turmas</h1><p>O professor visualiza somente turmas com aulas sob sua responsabilidade.</p></div></div>
<div class="card list-card">
@forelse($classes as $class)
    <div class="list-row"><span><strong>{{ $class->name }}</strong><small>{{ $class->status === 'active' ? 'Ativa' : 'Inativa' }} · início {{ $class->start_date->format('d/m/Y') }}</small></span></div>
@empty
    <div class="empty-state"><h2>Nenhuma turma disponível</h2><p>Confira o vínculo com a equipe e a designação nas aulas.</p></div>
@endforelse
</div>
{{ $classes->links() }}
@endsection
