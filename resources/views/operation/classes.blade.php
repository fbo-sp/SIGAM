@extends('layouts.app')
@section('title', 'Turmas — '.$dojo->name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Turmas</span></nav>
<div class="page-heading"><div><span class="eyebrow">Organização das aulas</span><h1>Turmas</h1><p>{{ $canManage ? 'Abra a turma para matricular alunos, designar professores e acompanhar as aulas.' : 'O professor visualiza somente turmas com aulas sob sua responsabilidade.' }}</p></div>@if($canManage)<a class="button button-primary" href="{{ route('operation.classes.create', $dojo) }}">Nova turma</a>@endif</div>
<div class="card list-card">
@forelse($classes as $class)
    <a class="list-row" href="{{ route('operation.classes.show', [$dojo, $class]) }}"><span><strong>{{ $class->name }}</strong><small>{{ $class->martialArt?->name }} · {{ $class->enrollments_count }} aluno(s){{ $class->capacity ? ' de '.$class->capacity : '' }}</small>@include('classes.partials.teacher-chips', ['group' => $class])</span><span class="status-pill {{ $class->status === 'active' ? '' : 'status-inactive' }}">{{ $class->status === 'active' ? 'Ativa' : 'Inativa' }}</span></a>
@empty
    <div class="empty-state"><h2>Nenhuma turma disponível</h2><p>{{ $canManage ? 'Crie a primeira turma deste dojo.' : 'Confira o vínculo com a equipe e a designação nas aulas.' }}</p></div>
@endforelse
</div>
{{ $classes->links() }}
@endsection
