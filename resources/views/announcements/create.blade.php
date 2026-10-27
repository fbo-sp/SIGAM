@extends('layouts.app')
@section('title', 'Enviar aviso — JUDOMA')
@php($value = fn (string $key, $default = null) => old($key, request()->query($key, $prefill[$key] ?? $default)))
@php($selected = fn (string $key) => array_map('intval', (array) $value($key, [])))
@section('content')
@include('partials.back-to-dashboard')
@include('announcements.partials.crumbs', ['current' => 'Enviar aviso'])
<section class="page-heading"><div><span class="eyebrow">{{ $dojo?->name ?? $association->trade_name }}</span><h1>Enviar aviso</h1><p>Escolha para quem, escreva a mensagem e revise antes de enviar.</p></div></section>
<form class="card entity-form announcement-form" method="post" action="{{ route($prefix.'.preview', $context) }}">
@csrf
<fieldset><legend>Para quem</legend>
<div class="choice-row" role="radiogroup" aria-label="Destino">@foreach($options['targets'] as $key => $label)<label class="check-row"><input type="radio" name="target_type" value="{{ $key }}" @checked($value('target_type') === $key)> {{ $label }}</label>@endforeach</div>
@error('target_type')<p class="field-error">{{ $message }}</p>@enderror
@if(isset($options['targets']['dojos']))
<div class="field" data-show-when="target_type:dojos"><span class="field-label">Dojos</span><div class="check-list">@foreach($options['dojos'] as $item)<label class="check-row"><input type="checkbox" name="dojo_ids[]" value="{{ $item->id }}" @checked(in_array($item->id, $selected('dojo_ids'), true))> {{ $item->name }}</label>@endforeach</div>@error('dojo_ids')<p class="field-error">{{ $message }}</p>@enderror</div>
@endif
<div class="field" data-show-when="target_type:classes"><span class="field-label">Turmas</span>@if($options['classes']->isEmpty())<p class="form-help">Nenhuma turma ativa disponível.</p>@else<div class="check-list">@foreach($options['classes'] as $item)<label class="check-row"><input type="checkbox" name="class_ids[]" value="{{ $item->id }}" @checked(in_array($item->id, $selected('class_ids'), true))> {{ $item->name }}@if($level === 'association') · {{ $item->dojo?->name }}@endif</label>@endforeach</div>@endif @error('class_ids')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field" data-show-when="target_type:people"><label for="people-filter">Pessoas</label><input id="people-filter" type="search" placeholder="Filtrar pelo nome" data-filter-list="people-list" autocomplete="off">
<div class="check-list check-list-tall" id="people-list">
@foreach($options['students'] as $student)<label class="check-row"><input type="checkbox" name="student_ids[]" value="{{ $student->id }}" @checked(in_array($student->id, $selected('student_ids'), true))> {{ $student->person->social_name ?: $student->person->full_name }} <small>aluno · {{ $student->registration_number }}</small></label>@endforeach
@foreach($options['teachers'] as $teacher)<label class="check-row"><input type="checkbox" name="teacher_ids[]" value="{{ $teacher->id }}" @checked(in_array($teacher->id, $selected('teacher_ids'), true))> {{ $teacher->person->social_name ?: $teacher->person->full_name }} <small>professor</small></label>@endforeach
</div>@error('student_ids')<p class="field-error">{{ $message }}</p>@enderror</div>
</fieldset>
<fieldset><legend>Quem recebe</legend>
@php($include = (array) $value('include', ['student', 'guardian']))
<div class="choice-row">@foreach($options['includes'] as $key => $label)<label class="check-row" @if($key === 'teacher') data-show-when="target_type:all,dojos,classes" @endif><input type="checkbox" name="include[]" value="{{ $key }}" @checked(in_array($key, $include, true))> {{ $label }}</label>@endforeach</div>
<p class="form-help announcement-help">Responsáveis são os vigentes marcados para receber comunicação.@if($level !== 'teacher') Professores escolhidos pelo nome sempre recebem.@endif</p>
@error('include')<p class="field-error">{{ $message }}</p>@enderror
</fieldset>
<fieldset><legend>Mensagem</legend><div class="form-grid">
<div class="field field-wide"><label for="title">Título</label><input id="title" name="title" maxlength="150" value="{{ $value('title') }}" required>@error('title')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field field-wide"><label for="body">Mensagem</label><textarea id="body" name="body" rows="7" maxlength="2000" required>{{ $value('body') }}</textarea><small>Até 2.000 caracteres. Sem respostas: quem recebe fala com o dojo pelos contatos habituais.</small>@error('body')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field field-wide"><input type="hidden" name="send_email" value="0"><label class="check-row"><input type="checkbox" name="send_email" value="1" @checked((string) $value('send_email', '1') === '1')> Enviar também por e-mail, com o texto completo, inclusive para quem não tem conta no JUDOMA</label></div>
</div></fieldset>
<div class="form-actions"><a class="button button-secondary" href="{{ route($prefix.'.index', $context) }}">Cancelar</a><button class="button button-primary" type="submit">Revisar envio</button></div>
</form>
@endsection
