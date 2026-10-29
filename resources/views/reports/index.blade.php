@extends('layouts.app')
@php($prefix = $context->routePrefix())
@php($params = $context->routeParameters())
@section('title', 'Relatórios — JUDOMA')
@section('content')
@if($context->level === 'association')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $context->association) }}">{{ $context->association->trade_name }}</a><span>/</span><span>Relatórios</span></nav>
@else
@include('partials.back-to-dashboard')
@endif
<div class="page-heading"><div><span class="eyebrow">{{ $context->level === 'dojo' ? $context->dojo->name : ($context->isTeacher() ? 'Professor' : $context->association->trade_name) }}</span><h1>Relatórios</h1><p>{{ match ($context->level) { 'association' => 'Exporte os dados de todos os dojos da associação.', 'dojo' => 'Exporte os dados dos alunos e turmas deste dojo.', default => 'Exporte os dados das turmas em que você é principal ou auxiliar.' } }}</p></div></div>

<section class="card report-card" aria-labelledby="relatorio-alunos">
<div class="report-card-head"><span class="report-icon" aria-hidden="true">XLS</span><div><h2 id="relatorio-alunos">Lista de alunos</h2><p>Matrícula, nome, idade, dojo, faixa, turmas, situação, entrada, {{ $context->isTeacher() ? '' : 'CPF, e-mail, ' }}celular e responsável.</p></div></div>
<form class="report-filters" method="get" action="{{ route($prefix.'students', $params) }}">
@if($context->dojos->count() > 1)<div class="field"><label for="dojo_id">Dojo</label><select id="dojo_id" name="dojo_id"><option value="">Todos</option>@foreach($context->dojos as $dojo)<option value="{{ $dojo->id }}" @selected($filters['dojo_id'] === $dojo->id)>{{ $dojo->name }}</option>@endforeach</select></div>@endif
<div class="field"><label for="class_group_id">Turma</label><select id="class_group_id" name="class_group_id"><option value="">{{ $context->isTeacher() ? 'Todas as suas turmas' : 'Todas' }}</option>@foreach($context->classes as $class)<option value="{{ $class->id }}" @selected($filters['class_group_id'] === $class->id)>{{ $class->name }}{{ $context->dojos->count() > 1 ? ' · '.$class->dojo?->name : '' }}</option>@endforeach</select></div>
@unless($context->isTeacher())<div class="field"><label for="situacao">Situação</label><select id="situacao" name="situacao">@foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($filters['situacao'] === $key)>{{ $label }}</option>@endforeach</select></div>@endunless
<div class="report-actions"><button class="button button-secondary" type="submit" formaction="{{ route($prefix.'index', $params) }}" name="previa" value="1">Ver total</button><button class="button button-primary" type="submit">Baixar Excel</button></div>
</form>
@if($preview !== null)<p class="report-preview" role="status"><strong>{{ $preview }} aluno(s)</strong> com esses filtros.@if($preview > $limit) O arquivo traz os primeiros {{ $limit }}; filtre por dojo ou turma.@endif</p>@endif
@if($context->isTeacher())<p class="form-help">Só alunos com matrícula ativa nas suas turmas. CPF e e-mail não entram na lista do professor.</p>@endif
</section>

<section class="section-block"><div class="section-heading"><h2>Próximos relatórios</h2><p>Chegam nas próximas versões, com os mesmos filtros.</p></div>
<div class="card list-card">@foreach(['Lista de presença (PDF)', 'Frequência resumida (Excel)', 'Frequência detalhada (Excel)', 'Técnicas ministradas (Excel com gráficos)'] as $next)<div class="list-row"><span>{{ $next }}</span><span class="status-pill status-inactive">Em breve</span></div>@endforeach</div>
</section>
@endsection
