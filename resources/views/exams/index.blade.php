@extends('layouts.app')
@section('title', 'Exames de graduação — JUDOMA')
@section('content')
@include('exams.partials.crumbs')
<div class="page-heading"><div><span class="eyebrow">{{ $context->label() }}</span><h1>Exames de graduação</h1><p>{{ match ($context->level) { 'association' => 'Exames da associação, dos dojos, das turmas e por aluno.', 'dojo' => 'Exames deste dojo, das turmas dele e por aluno.', default => 'Exames em que você faz parte da banca.' } }}</p></div>@if($context->canManage())<a class="button button-primary" href="{{ $context->route('create') }}">Novo exame</a>@endif</div>
<section class="dashboard-metrics exam-metrics" aria-label="Resumo dos exames">
<article class="card"><span>Abertos</span><strong>{{ $counts['open'] ?? 0 }}</strong></article>
<article class="card"><span>Rascunhos</span><strong>{{ $counts['draft'] ?? 0 }}</strong></article>
<article class="card"><span>Encerrados</span><strong>{{ $counts['closed'] ?? 0 }}</strong></article>
</section>
<nav class="chip-filters" aria-label="Situação">
@foreach(['abertos' => 'Abertos', 'rascunhos' => 'Rascunhos', 'encerrados' => 'Encerrados e cancelados'] as $key => $label)<a href="{{ $context->route('index', ['aba' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav>
<section class="card list-card">
@forelse($exams as $exam)
<a class="list-row" href="{{ $context->route('show', ['exam' => $exam]) }}"><span><strong>{{ $exam->name }}</strong><small>{{ $exam->martialArt->name }} · {{ \App\Models\RankExam::SCOPES[$exam->scope_type] }}@if($exam->scope_type === 'student' && $exam->student) ({{ $exam->student->person->social_name ?: $exam->student->person->full_name }})@elseif($exam->scope_type !== 'association') ({{ $exam->scopeLabel() }})@endif · {{ $exam->starts_on->format('d/m/Y') }} a {{ $exam->ends_on->format('d/m/Y') }} · média {{ number_format((float) $exam->minimum_average, 1, ',', '') }}</small><small>{{ $exam->enrolled_count }} aluno(s)@if($exam->quiz_enabled) · com quiz @endif</small></span>@include('exams.partials.status')</a>
@empty
<div class="empty-state"><h2>Nenhum exame aqui</h2><p>{{ $context->canManage() ? 'Use "Novo exame" para começar um rascunho.' : 'Quando você estiver na banca de um exame, ele aparece aqui.' }}</p></div>
@endforelse
</section>
@endsection
