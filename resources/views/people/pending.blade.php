@extends('layouts.app')
@section('title', 'Cadastros incompletos — JUDOMA')
@section('content')
@if($context->level === 'association')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $context->association) }}">{{ $context->association->trade_name }}</a><span>/</span><span>Cadastros incompletos</span></nav>
@else
@include('partials.back-to-dashboard')
@endif
<div class="page-heading"><div><span class="eyebrow">{{ $context->level === 'dojo' ? $context->dojo->name : ($context->isTeacher() ? 'Professor' : $context->association->trade_name) }}</span><h1>Cadastros incompletos</h1><p>{{ $context->isTeacher() ? 'Alunos das suas turmas com dados que faltam. Peça os dados no treino e avise o gestor do dojo.' : 'Alunos ativos e em pré-cadastro com dados que faltam. Corrija pelo botão de cada aluno.' }}</p></div></div>
<form class="card filter-bar pending-filters" method="get" action="{{ $action }}">
@if($context->level === 'association')<div class="field"><label for="dojo">Dojo</label><select id="dojo" name="dojo"><option value="">Todos</option>@foreach($context->dojos as $dojo)<option value="{{ $dojo->id }}" @selected($filters['dojo_id'] === $dojo->id)>{{ $dojo->name }}</option>@endforeach</select></div>@endif
<div class="field"><label for="turma">Turma</label><select id="turma" name="turma"><option value="">Todas</option>@foreach($context->classes as $group)<option value="{{ $group->id }}" @selected($filters['class_group_id'] === $group->id)>{{ $group->name }}{{ $context->level === 'association' || $context->dojos->count() > 1 ? ' · '.$group->dojo?->name : '' }}</option>@endforeach</select></div>
<div class="field"><label for="busca">Busca</label><input id="busca" name="busca" type="search" value="{{ $filters['search'] }}" placeholder="Nome ou matrícula"></div>
@if($type)<input type="hidden" name="tipo" value="{{ $type }}">@endif
<button class="button button-secondary" type="submit">Filtrar</button>
</form>
@php($query = array_filter(['dojo' => $filters['dojo_id'], 'turma' => $filters['class_group_id'], 'busca' => $filters['search']]))
<nav class="chip-filters" aria-label="Tipo de pendência"><a href="{{ $action.($query ? '?'.http_build_query($query) : '') }}" @if(! $type) aria-current="page" @endif>Todos ({{ $total }})</a>@foreach($counts as $key => $count)<a href="{{ $action.'?'.http_build_query($query + ['tipo' => $key]) }}" @if($type === $key) aria-current="page" @endif>{{ \App\Services\People\PendingDataService::ITEMS[$key][0] }} ({{ $count }})</a>@endforeach</nav>
<div class="card list-card pending-list">@forelse($rows as $row)
@php($person = $row['student']->person)
@php($url = match ($context->level) { 'association' => route('associations.students.show', [$context->association, $row['student']]), 'dojo' => $canFix ? route('operation.students.person.edit', [$context->dojo, $row['student']]) : null, default => $row['dojo'] ? route('operation.progression.show', [$row['dojo'], $row['student']]) : null })
<div class="list-row"><span class="pending-who"><x-person-photo :person="$person" /><span><strong>{{ $person->social_name ?: $person->full_name }}</strong><small>{{ collect([$context->level === 'association' ? $row['dojo']?->name : null, $row['classes'] ?: 'Sem turma', $row['student']->status === 'pre_registration' ? 'Pré-cadastro' : null])->filter()->implode(' · ') }}</small>@include('people.partials.pending-tags', ['items' => $row['items']])</span></span>@php($guardianUrl = ! in_array('guardian', $row['items'], true) ? null : match ($context->level) { 'association' => route('associations.students.show', [$context->association, $row['student']]).'#responsaveis', 'dojo' => $canFix ? route('operation.students.guardians.index', [$context->dojo, $row['student']]) : null, default => $row['dojo'] ? route('operation.students.guardians.index', [$row['dojo'], $row['student']]) : null })
<div class="row-actions">@if($guardianUrl)<a class="button button-secondary" href="{{ $guardianUrl }}">Adicionar responsável</a>@endif @if($url)<a class="button button-secondary" href="{{ $url }}">{{ $context->isTeacher() ? 'Ficha' : 'Completar' }}</a>@endif</div></div>
@empty<div class="empty-state"><p>Nenhum cadastro incompleto{{ $type ? ' neste tipo' : '' }}.</p></div>@endforelse</div>
@endsection
