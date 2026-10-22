@extends('layouts.app')
@section('title', 'Professores — '.$dojo->name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>Professores</span></nav>
<div class="page-heading"><div><span class="eyebrow">Equipe local</span><h1>Professores do dojo</h1><p>Somente professores previamente cadastrados pela associação podem ser vinculados.</p></div></div>
@if($canAssign)
<details class="card disclosure" @if($errors->any()) open @endif><summary>Vincular professor ao dojo</summary>
<form method="post" action="{{ route('operation.teachers.link', $dojo) }}" class="context-form">@csrf
    <div class="field"><label for="teacher_profile_id">Professor da associação</label><select id="teacher_profile_id" name="teacher_profile_id" required><option value="">Selecione</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}" @selected(old('teacher_profile_id') == $teacher->id)>{{ $teacher->person->full_name }}</option>@endforeach</select><small>Não encontrou? O cadastro institucional deve ser feito pela associação.</small></div>
    <div class="field"><label for="start_date">Início</label><input id="start_date" name="start_date" type="date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required></div>
    <div class="field"><label for="end_date">Término, se houver</label><input id="end_date" name="end_date" type="date" value="{{ old('end_date') }}"></div>
    <button class="button button-primary">Vincular professor</button>
</form></details>
@endif
<div class="card list-card">
@forelse($links as $link)
    <div class="list-row context-row"><span><strong>{{ $link->teacher->person->full_name }}</strong><small>{{ $link->start_date->format('d/m/Y') }} até {{ $link->end_date?->format('d/m/Y') ?? 'sem data final' }} · {{ $link->revoked_at ? 'Encerrado' : 'Vigente conforme período' }}</small></span><div class="row-actions">@if($canEditPeople)<x-edit-link :href="route('associations.people.edit', [$dojo->association_id, $link->teacher->person_id, 'voltar' => request()->getRequestUri()])" :label="'Editar dados de '.$link->teacher->person->full_name" />@endif @if($canAssign && !$link->revoked_at)<form method="post" action="{{ route('operation.teachers.unlink', [$dojo, $link]) }}">@csrf<button class="button button-secondary">Encerrar vínculo</button></form>@endif</div></div>
@empty
    <div class="empty-state"><h2>Nenhum professor vinculado</h2><p>Escolha um professor já aprovado pela associação.</p></div>
@endforelse
</div>
{{ $links->links() }}
@endsection
