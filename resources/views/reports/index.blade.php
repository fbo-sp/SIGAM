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

<section class="card report-card" aria-labelledby="relatorio-frequencia">
<div class="report-card-head"><span class="report-icon" aria-hidden="true">XLS</span><div><h2 id="relatorio-frequencia">Frequência</h2><p><strong>Resumida:</strong> aulas, presenças, faltas e % por aluno e turma. <strong>Detalhada:</strong> aula a aula, com quem deu a aula e os auxiliares.</p></div></div>
<form class="report-filters" method="get" action="{{ route($prefix.'attendance', $params + ['kind' => 'resumida']) }}">
<div class="field"><label for="freq_de">De</label><input id="freq_de" type="date" name="de" value="{{ $period['from']->toDateString() }}" required></div>
<div class="field"><label for="freq_ate">Até</label><input id="freq_ate" type="date" name="ate" value="{{ $period['to']->toDateString() }}" required></div>
@if($context->dojos->count() > 1)<div class="field"><label for="freq_dojo_id">Dojo</label><select id="freq_dojo_id" name="dojo_id"><option value="">Todos</option>@foreach($context->dojos as $dojo)<option value="{{ $dojo->id }}" @selected($filters['dojo_id'] === $dojo->id)>{{ $dojo->name }}</option>@endforeach</select></div>@endif
<div class="field"><label for="freq_class_group_id">Turma</label><select id="freq_class_group_id" name="class_group_id"><option value="">{{ $context->isTeacher() ? 'Todas as suas turmas' : 'Todas' }}</option>@foreach($context->classes as $class)<option value="{{ $class->id }}" @selected($filters['class_group_id'] === $class->id)>{{ $class->name }}{{ $context->dojos->count() > 1 ? ' · '.$class->dojo?->name : '' }}</option>@endforeach</select></div>
<div class="report-actions"><button class="button button-secondary" type="submit" formaction="{{ route($prefix.'index', $params) }}" name="previa" value="frequencia">Ver total</button><button class="button button-primary" type="submit">Baixar resumida</button><button class="button button-primary" type="submit" formaction="{{ route($prefix.'attendance', $params + ['kind' => 'detalhada']) }}">Baixar detalhada</button></div>
</form>
@error('ate')<p class="field-error" role="alert">{{ $message }}</p>@enderror
@if($attendancePreview !== null)<p class="report-preview" role="status"><strong>{{ $attendancePreview['closed'] }} aula(s) com chamada finalizada</strong>@if($attendancePreview['open']) e {{ $attendancePreview['open'] }} com chamada aberta (não contam)@endif · {{ $attendancePreview['students'] }} aluno(s) · {{ $attendancePreview['lines'] }} linha(s) na detalhada.@if($attendancePreview['lines'] > $detailLimit) A detalhada traz as primeiras {{ $detailLimit }}; filtre por turma ou período.@endif</p>@endif
<p class="form-help">Só chamadas finalizadas contam. Período de até 366 dias; aulas futuras ficam fora.</p>
</section>

<section class="section-block"><div class="section-heading"><h2>Próximos relatórios</h2><p>Chegam nas próximas versões, com os mesmos filtros.</p></div>
<div class="card list-card">@foreach(['Lista de presença (PDF)', 'Técnicas ministradas (Excel com gráficos)'] as $next)<div class="list-row"><span>{{ $next }}</span><span class="status-pill status-inactive">Em breve</span></div>@endforeach</div>
</section>
@endsection
