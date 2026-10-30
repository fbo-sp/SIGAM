@extends('layouts.app')
@section('title', 'Participantes · '.$exam->name.' — JUDOMA')
@section('content')
@include('exams.partials.crumbs', ['here' => 'Participantes'])
<div class="page-heading"><div><span class="eyebrow">Passo 2 · Participantes</span><h1>Participantes</h1><p>{{ $rows->count() }} aluno(s) ativo(s) {{ $exam->scope_type === 'association' ? 'da associação' : 'de '.$exam->scopeLabel() }} com próxima faixa em {{ $exam->martialArt->name }}. Apto = idade e tempo mínimo na faixa atual cumpridos em {{ $exam->starts_on->format('d/m/Y') }}.</p></div></div>
@include('exams.partials.steps', ['step' => 'participantes'])
@if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $message)<span>{{ $message }}</span>@endforeach</div>@endif
<form method="post" action="{{ $context->route('participants.save', ['exam' => $exam]) }}">@csrf @method('put')
@if($rows->isEmpty())
<div class="card empty-state"><h2>Nenhum aluno encontrado</h2><p>Confira se os alunos têm a modalidade ativa e uma próxima faixa cadastrada.</p></div>
@else
@if($rows->count() > 8)<div class="field exam-filter"><label for="participant-filter">Procurar aluno</label><input id="participant-filter" type="search" placeholder="Filtrar pelo nome" data-filter-list="exam-participant-list" autocomplete="off"></div>@endif
<div class="card list-card exam-participants" id="exam-participant-list">
@foreach($rows as $row)
@php($id = $row['student']->id)
@php($checked = old("students.$id.include", $fresh ? ($row['eligible'] && ! $row['other_exam']) : $row['included']))
<div class="exam-participant {{ $row['eligible'] ? '' : 'is-warn' }}">
<label class="check-row exam-participant-main"><input type="hidden" name="students[{{ $id }}][include]" value="0"><input type="checkbox" name="students[{{ $id }}][include]" value="1" @checked($checked)>
<x-person-photo :person="$row['student']->person" />
<span><strong>{{ $row['student']->person->social_name ?: $row['student']->person->full_name }}</strong><small>{{ $row['current_rank']?->name ?? 'Sem faixa' }} → {{ $row['target_rank']->name }}@if($row['months'] !== null) · {{ $row['months'] }} {{ $row['months'] === 1 ? 'mês' : 'meses' }} na faixa @endif</small>
@if($row['unmet'])<small class="text-warning">Não está apto: {{ implode('; ', $row['unmet']) }}.</small>@endif
@if($row['unknown'])<small>Não verificado: {{ implode('; ', $row['unknown']) }}.</small>@endif
@if($row['other_exam'])<small class="text-warning">Já está no exame "{{ $row['other_exam']->name }}".</small>@endif
@if($row['outside'])<small class="text-warning">Não está mais no nível do exame.</small>@endif
</span>
<span class="exam-pill {{ $row['eligible'] ? 'is-ok' : 'is-warn' }}">{{ $row['eligible'] ? 'Apto' : 'Não apto' }}</span></label>
@unless($row['eligible'])<div class="field exam-reason"><label for="reason-{{ $id }}">Motivo para incluir mesmo assim</label><input id="reason-{{ $id }}" name="students[{{ $id }}][reason]" value="{{ old("students.$id.reason", $row['participant']?->inclusion_reason) }}" maxlength="1000" placeholder="Obrigatório se marcar">@error("students.$id.reason")<p class="field-error">{{ $message }}</p>@enderror</div>@endunless
@error("students.$id.include")<p class="field-error exam-reason">{{ $message }}</p>@enderror
</div>
@endforeach
</div>
@endif
<div class="form-actions"><a class="button button-secondary" href="{{ $context->route('show', ['exam' => $exam]) }}">Voltar</a>@if($rows->isNotEmpty())<button class="button button-primary">Salvar e ver o conteúdo</button>@endif</div>
</form>
@endsection
