@extends('layouts.app')
@section('title', 'Configurações do acompanhamento')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><a href="{{ route('associations.catalog.index', $association) }}">Modalidades</a><span>/</span><span>Configurações do acompanhamento</span></nav>
<div class="page-heading"><div><span class="eyebrow">Acompanhamento técnico</span><h1>Configurações</h1><p>Valem para todas as modalidades da associação.</p></div></div>
<form class="card form-grid" method="post" action="{{ route('associations.progression.settings.update', $association) }}">@csrf @method('PUT')
<div class="field"><label for="quiz_question_count">Perguntas por quiz</label><input id="quiz_question_count" name="quiz_question_count" type="number" min="1" max="50" value="{{ old('quiz_question_count', $settings->quiz_question_count) }}" required>@error('quiz_question_count')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="quiz_minimum_score">Nota mínima da prova oficial (%)</label><input id="quiz_minimum_score" name="quiz_minimum_score" type="number" min="1" max="100" value="{{ old('quiz_minimum_score', $settings->quiz_minimum_score) }}" required>@error('quiz_minimum_score')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="assessment_reminder_weeks">Lembrar o professor após (semanas sem avaliação)</label><input id="assessment_reminder_weeks" name="assessment_reminder_weeks" type="number" min="1" max="52" value="{{ old('assessment_reminder_weeks', $settings->assessment_reminder_weeks) }}" required>@error('assessment_reminder_weeks')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="form-actions field-wide"><button class="button button-primary">Salvar</button></div></form>
@endsection
