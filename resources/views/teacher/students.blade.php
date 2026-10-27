@extends('layouts.app')
@section('title', 'Meus alunos — JUDOMA')
@section('content')
<div class="page-heading"><div><span class="eyebrow">Professor</span><h1>Meus alunos</h1><p>Alunos matriculados nas suas turmas.</p></div></div>
<form class="card filter-bar teacher-search" method="get" action="{{ route('teacher.students') }}"><div class="field filter-grow"><label for="busca">Buscar aluno</label><input id="busca" name="busca" type="search" value="{{ $search }}" placeholder="Nome ou matrícula"></div>@if($filter)<input type="hidden" name="filtro" value="{{ $filter }}">@endif<button class="button button-secondary">Buscar</button></form>
<nav class="chip-filters" aria-label="Filtros">
<a href="{{ route('teacher.students', array_filter(['busca' => $search])) }}" @if(! $filter) aria-current="page" @endif>Todos</a>
<a href="{{ route('teacher.students', array_filter(['busca' => $search, 'filtro' => 'faltando'])) }}" @if($filter === 'faltando') aria-current="page" @endif>Faltando ({{ $counts['faltando'] }})</a>
<a href="{{ route('teacher.students', array_filter(['busca' => $search, 'filtro' => 'prontos'])) }}" @if($filter === 'prontos') aria-current="page" @endif>Prontos ({{ $counts['prontos'] }})</a>
</nav>
@if($announceGroups->isNotEmpty())
<div class="row-actions teacher-announce">@foreach($announceGroups as $group)<a class="button button-secondary" href="{{ route('operation.announcements.create', [$group['dojo'], 'target_type' => 'people', 'student_ids' => $group['student_ids']]) }}">Avisar {{ count($group['student_ids']) === 1 ? 'este aluno' : 'estes '.count($group['student_ids']).' alunos' }}@if($announceGroups->count() > 1) ({{ $group['dojo']->name }})@endif</a>@endforeach</div>
@endif
<div class="card list-card">
@forelse($rows as $row)
@php($person = $row['student']->person)
@php($phone = $person->mobile ?: $person->phone)
<div class="list-row"><span class="person-cell"><x-person-photo :person="$person" /><span><strong>{{ $person->social_name ?: $person->full_name }}</strong><small>{{ $row['rank'] ?: 'Sem graduação' }} · {{ $row['class_names'] }}@if($row['absent']) · <span class="text-warning">3 faltas seguidas</span>@endif @if($row['ready']) · <span class="text-success">pronto para {{ $row['next_rank'] }}</span>@endif</small>@if($phone)<small><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></small>@endif</span></span>
<div class="row-actions">@if($announceDojos->has($row['dojo']->id))<a class="button button-secondary" href="{{ route('operation.announcements.create', [$row['dojo'], 'target_type' => 'people', 'student_ids' => [$row['student']->id]]) }}">Aviso</a>@endif<a class="button button-secondary" href="#emergencia-{{ $row['student']->id }}" data-modal-open="emergencia-{{ $row['student']->id }}">Emergência</a><a class="button button-secondary" href="{{ route('operation.progression.show', [$row['dojo'], $row['student'], 'modalidade' => $row['martial_art_id']]) }}">Ficha</a></div></div>
@empty
<div class="empty-state"><p>{{ $search || $filter ? 'Nenhum aluno encontrado com esse filtro.' : 'Nenhum aluno matriculado nas suas turmas.' }}</p></div>
@endforelse
</div>
@foreach($rows->unique(fn ($row) => $row['student']->id) as $row)@include('operation.partials.emergency-contact', ['student' => $row['student']])@endforeach
@endsection
