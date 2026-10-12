@extends('layouts.app')

@section('title', 'Meu portal — JUDOMA')

@section('content')
<section class="page-heading">
    <div>
        <span class="eyebrow">Área pessoal</span>
        <h1>Meu portal</h1>
        <p>Consulte suas informações e as dos dependentes vinculados à sua conta.</p>
    </div>
</section>

@if($students->isEmpty())
    <section class="card empty-state">
        <h2>Nenhum aluno vinculado</h2>
        <p>Peça à associação para conferir a pessoa ligada à sua conta e os vínculos de responsável.</p>
    </section>
@else
    <section class="portal-person-grid" aria-label="Escolha quem deseja consultar">
        @foreach($students as $student)
            <a class="card portal-person-card" href="{{ route('portal.students.show', $student) }}">
                <span class="status-pill">{{ $student->person_id === auth()->user()->person_id ? 'Meus dados' : 'Dependente' }}</span>
                <h2>{{ $student->person->social_name ?: $student->person->full_name }}</h2>
                <p>Matrícula {{ $student->registration_number }} · {{ $student->primaryDojo?->name ?: 'Dojo não informado' }}</p>
                <strong>Consultar informações</strong>
            </a>
        @endforeach
    </section>
@endif
@endsection
