@extends('layouts.app')
@section('title', ($exam ? 'Editar exame' : 'Novo exame').' — JUDOMA')
@section('content')
@php($value = fn (string $field, $default = null) => old($field, $exam?->{$field} ?? $default))
@php($selectedExaminers = collect(old('examiner_ids', $exam?->examiners->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all())
@include('exams.partials.crumbs', ['here' => $exam ? 'Dados' : 'Novo'])
<div class="page-heading"><div><span class="eyebrow">{{ $exam ? 'Passo 1 · Dados' : 'Passo 1 de 4 · Dados' }}</span><h1>{{ $exam ? 'Dados do exame' : 'Novo exame' }}</h1><p>Depois de salvar, você escolhe os participantes e confere o conteúdo de cada faixa.</p></div></div>
@if($exam)@include('exams.partials.steps', ['step' => 'dados'])@endif
<form class="card entity-form" method="post" action="{{ $exam ? $context->route('update', ['exam' => $exam]) : $context->route('store') }}">
@csrf
@if($exam) @method('put') @endif
<fieldset><legend>Exame</legend><div class="form-grid">
<div class="field field-wide"><label for="name">Nome</label><input id="name" name="name" value="{{ $value('name') }}" maxlength="150" required placeholder="Ex.: Exame de faixas · Dezembro 2026">@error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="martial_art_id">Modalidade</label>
@if($locked)<input type="hidden" name="martial_art_id" value="{{ $exam->martial_art_id }}"><input id="martial_art_id" value="{{ $exam->martialArt->name }}" disabled>@else<select id="martial_art_id" name="martial_art_id" required>@foreach($martialArts as $martialArt)<option value="{{ $martialArt->id }}" @selected((int) $value('martial_art_id') === $martialArt->id)>{{ $martialArt->name }}</option>@endforeach</select>@endif
</div>
<div class="field"><label for="minimum_average">Média mínima (0 a 10)</label><input id="minimum_average" name="minimum_average" inputmode="decimal" value="{{ str_replace('.', ',', (string) old('minimum_average', $exam ? number_format((float) $exam->minimum_average, 1, '.', '') : '7.0')) }}" required>@error('minimum_average')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="starts_on">Início</label><input id="starts_on" type="date" name="starts_on" value="{{ old('starts_on', $exam?->starts_on?->toDateString() ?? today()->toDateString()) }}" required>@error('starts_on')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="ends_on">Fim</label><input id="ends_on" type="date" name="ends_on" value="{{ old('ends_on', $exam?->ends_on?->toDateString() ?? today()->addDays(14)->toDateString()) }}" required>@error('ends_on')<p class="field-error">{{ $message }}</p>@enderror</div>
</div></fieldset>
<fieldset><legend>Para quem</legend>
@if($locked)
<input type="hidden" name="scope_type" value="{{ $exam->scope_type }}">
<p class="form-help exam-left">{{ \App\Models\RankExam::SCOPES[$exam->scope_type] }}@if($exam->scope_type !== 'association') · {{ $exam->scope_type === 'student' ? ($exam->student?->person?->full_name) : $exam->scopeLabel() }}@endif. O nível não muda depois que o exame tem participantes.</p>
@if($exam->scope_type === 'student')<div class="field"><label for="justification">Justificativa do exame individual</label><textarea id="justification" name="justification" required>{{ old('justification', $exam->justification) }}</textarea>@error('justification')<p class="field-error">{{ $message }}</p>@enderror</div>@endif
@else
<div class="exam-scope" role="radiogroup" aria-label="Nível do exame">@foreach($scopes as $key => $label)<label class="exam-scope-option"><input type="radio" name="scope_type" value="{{ $key }}" @checked($value('scope_type', $context->dojo ? 'dojo' : 'association') === $key)> {{ $label }}</label>@endforeach</div>
@error('scope_type')<p class="field-error">{{ $message }}</p>@enderror
@unless($context->dojo)<div class="field" data-show-when="scope_type:dojo"><label for="dojo_id">Dojo</label><select id="dojo_id" name="dojo_id"><option value="">Escolha o dojo</option>@foreach($dojos as $dojo)<option value="{{ $dojo->id }}" @selected((int) $value('dojo_id') === $dojo->id)>{{ $dojo->name }}</option>@endforeach</select>@error('dojo_id')<p class="field-error">{{ $message }}</p>@enderror</div>@endunless
<div class="field" data-show-when="scope_type:class"><label for="class_group_id">Turma</label><select id="class_group_id" name="class_group_id"><option value="">Escolha a turma</option>@foreach($classes as $class)<option value="{{ $class->id }}" @selected((int) $value('class_group_id') === $class->id)>{{ $class->name }}{{ $context->dojo ? '' : ' · '.$class->dojo?->name }}</option>@endforeach</select>@error('class_group_id')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field" data-show-when="scope_type:student"><label for="student-filter">Aluno</label><input id="student-filter" type="search" placeholder="Filtrar pelo nome" data-filter-list="exam-students" autocomplete="off">
<div class="check-list exam-student-list" id="exam-students">@foreach($students as $student)<label class="check-row"><input type="radio" name="student_id" value="{{ $student->id }}" @checked((int) $value('student_id') === $student->id)> {{ $student->person->full_name }}@if($student->registration_number) · {{ $student->registration_number }}@endif</label>@endforeach</div>
@error('student_id')<p class="field-error">{{ $message }}</p>@enderror
<label for="justification">Justificativa (obrigatória no exame de um aluno)</label><textarea id="justification" name="justification" placeholder="Ex.: voltou de lesão e perdeu o exame da turma.">{{ $value('justification') }}</textarea>@error('justification')<p class="field-error">{{ $message }}</p>@enderror</div>
@endif
</fieldset>
<fieldset><legend>Banca (quem avalia)</legend>
<label class="check-row"><input type="checkbox" name="class_teachers_examine" value="1" @checked(old('class_teachers_examine', $exam?->class_teachers_examine ?? true))> Professores das turmas dos alunos (principal e auxiliares)</label>
@if($teachers->isNotEmpty())<p class="form-help exam-left">Outros professores da banca:</p><div class="check-list">@foreach($teachers as $teacher)<label class="check-row"><input type="checkbox" name="examiner_ids[]" value="{{ $teacher->id }}" @checked(in_array($teacher->id, $selectedExaminers, true))> {{ $teacher->person->social_name ?: $teacher->person->full_name }}</label>@endforeach</div>@endif
<p class="form-help exam-left">A banca pode desmarcar itens do conteúdo e, quando o exame abrir, lança as notas.</p>
</fieldset>
<fieldset><legend>Prova teórica (quiz)</legend>
<div class="exam-scope" role="radiogroup" aria-label="Prova teórica"><label class="exam-scope-option"><input type="radio" name="quiz_enabled" value="0" @checked(! old('quiz_enabled', $exam?->quiz_enabled ?? false))> Sem quiz</label><label class="exam-scope-option"><input type="radio" name="quiz_enabled" value="1" @checked((bool) old('quiz_enabled', $exam?->quiz_enabled ?? false))> Com quiz</label></div>
<div class="form-grid" data-show-when="quiz_enabled:1">
<div class="field"><label for="quiz_question_count">Perguntas por aluno</label><input id="quiz_question_count" type="number" min="1" max="50" name="quiz_question_count" value="{{ old('quiz_question_count', $exam?->quiz_question_count ?? $defaultQuestions) }}">@error('quiz_question_count')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="quiz_time_limit_minutes">Tempo limite em minutos (opcional)</label><input id="quiz_time_limit_minutes" type="number" min="1" max="180" name="quiz_time_limit_minutes" value="{{ old('quiz_time_limit_minutes', $exam?->quiz_time_limit_minutes) }}" placeholder="sem limite">@error('quiz_time_limit_minutes')<p class="field-error">{{ $message }}</p>@enderror</div>
<p class="form-help exam-left field-wide">Usa o banco do quiz de treino. Cada aluno recebe perguntas da faixa que vai receber e entra como um item de nota (grupo Teoria, que você pode mudar no conteúdo).</p>
</div>
</fieldset>
<div class="form-actions"><a class="button button-secondary" href="{{ $exam ? $context->route('show', ['exam' => $exam]) : $context->route('index') }}">Voltar</a><button class="button button-primary">{{ $exam ? 'Salvar dados' : 'Salvar e escolher participantes' }}</button></div>
</form>
@endsection
