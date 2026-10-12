@extends('layouts.app')
@section('title', 'Alunos — '.$dojo->name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Alunos</span></nav>
<div class="page-heading"><div><span class="eyebrow">Vínculos regulares</span><h1>Alunos do dojo</h1><p>Esta lista não inclui visitantes e não exibe documentos, contatos ou dados financeiros.</p></div>@if($canManage)<a class="button button-primary" href="{{ route('operation.students.direct.create', $dojo) }}">Novo aluno</a>@endif</div>
<div class="card list-card">
@forelse($students as $student)
    <div class="list-row"><span><strong>{{ $student->person->social_name ?: $student->person->full_name }}</strong><small>Matrícula {{ $student->registration_number }} · {{ $student->status === 'active' ? 'Ativo' : 'Em cadastro' }}</small></span></div>
@empty
    <div class="empty-state"><h2>Nenhum aluno com vínculo regular</h2><p>Visitantes serão tratados por aula em uma etapa posterior, sem aparecer nesta relação.</p></div>
@endforelse
</div>
{{ $students->links() }}
@endsection
