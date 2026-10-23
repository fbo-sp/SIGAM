@extends('layouts.app')
@section('title', 'Quiz — '.$martialArt->name)
@section('content')
@include('progression._tabs', ['current' => 'quiz', 'title' => 'Banco de perguntas do quiz', 'subtitle' => "Perguntas teóricas por graduação. Cada quiz sorteia {$settings->quiz_question_count} pergunta(s); a nota mínima da prova oficial é {$settings->quiz_minimum_score}%."])

@if($ranks->isEmpty())
<div class="card empty-state"><h2>Nenhuma graduação cadastrada</h2><p>Cadastre as graduações da modalidade antes de montar o quiz.</p></div>
@else
<form class="card filter-form rank-picker" method="get" action="{{ route('associations.quiz.index', [$association, $martialArt]) }}"><div class="field"><label for="graduacao">Graduação</label><select id="graduacao" name="graduacao">@foreach($ranks as $option)<option value="{{ $option->id }}" @selected($option->id === $rank->id)>{{ $option->display_name }} ({{ $counts[$option->id] ?? 0 }})</option>@endforeach</select></div><button class="button button-secondary">Ver perguntas</button></form>

<details class="card disclosure" @if($errors->any()) open @endif><summary>Incluir pergunta</summary><form class="form-grid" method="post" action="{{ route('associations.quiz.store', [$association, $martialArt]) }}">@csrf
@include('progression._quiz-form', ['question' => null])
<div class="form-actions field-wide"><button class="button button-primary">Incluir pergunta</button></div></form></details>

<div class="section-heading"><h2>Perguntas de {{ $rank->display_name }}</h2><p>{{ $counts[$rank->id] ?? 0 }} ativa(s). Quando a graduação tem menos perguntas que o sorteio, o quiz completa com as das graduações anteriores.</p></div>
@if($questions->isEmpty())
<div class="card empty-state"><h2>Nenhuma pergunta nesta graduação</h2><p>Inclua perguntas acima ou aplique o modelo na aba Catálogo de técnicas.</p></div>
@else
<div class="card list-card">
@foreach($questions as $question)
<div class="list-row quiz-row"><span><strong>{{ $question->statement }}</strong><small>Resposta: {{ $question->options->firstWhere('is_correct', true)?->text ?? 'sem alternativa correta' }}@if($question->item) · {{ $question->item->name }}@endif</small></span>
<div class="row-actions">@if($question->status !== 'active')<span class="status-pill status-inactive">Inativa</span>@endif
<a class="button button-secondary" href="{{ route('associations.quiz.edit', [$association, $martialArt, $question]) }}" aria-label="Editar pergunta {{ $loop->iteration }}">Editar</a>
<form method="post" action="{{ route('associations.quiz.status', [$association, $martialArt, $question]) }}">@csrf<input type="hidden" name="status" value="{{ $question->status === 'active' ? 'inactive' : 'active' }}"><button class="button button-secondary">{{ $question->status === 'active' ? 'Inativar' : 'Reativar' }}</button></form></div></div>
@endforeach</div>
@endif
@endif
@endsection
