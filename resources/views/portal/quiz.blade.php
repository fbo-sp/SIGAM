@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($finished = $attempt->status === 'finished')
@section('title', ($attempt->mode === 'official' ? 'Prova oficial' : 'Treino').' do quiz — '.$name)
@section('content')
<nav class="breadcrumbs" aria-label="Caminho"><a href="{{ route('portal.index') }}">Meu portal</a><span>/</span><a href="{{ route('portal.progress', [$student, 'modalidade' => $attempt->martial_art_id]) }}">Progresso técnico</a><span>/</span><span>Quiz</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $attempt->martialArt->name }} · {{ $attempt->rank->display_name }}</span><h1>{{ $attempt->mode === 'official' ? 'Prova oficial do quiz' : 'Treino do quiz' }}</h1>
<p>{{ $attempt->question_count }} pergunta(s).@if($attempt->mode === 'official') Nota mínima {{ $attempt->minimum_score }}%. Uma tentativa: ao enviar, não dá para refazer.@else Treino não conta para o acompanhamento.@endif</p></div></div>

@if($finished)
<div class="card quiz-result"><p class="quiz-score">{{ $attempt->score }}%</p><p>{{ $attempt->correct_count }} de {{ $attempt->question_count }} acerto(s).@if($attempt->mode === 'official') {{ $attempt->passed ? 'Aprovado: os itens teóricos das perguntas foram registrados como Adequado.' : 'Abaixo da nota mínima. Fale com o professor para uma nova liberação.' }}@endif</p>
<a class="button button-secondary" href="{{ route('portal.progress', [$student, 'modalidade' => $attempt->martial_art_id]) }}">Voltar ao progresso</a></div>
@foreach($attempt->answers->sortBy('sort_order') as $answer)
@php($options = collect($answer->option_ids)->map(fn ($id) => $answer->question->options->firstWhere('id', $id))->filter())
<fieldset class="card quiz-question"><legend>{{ $loop->iteration }}. {{ $answer->question->statement }}</legend>
@foreach($options as $option)<div @class(['quiz-answer', 'is-correct' => $option->is_correct, 'is-wrong' => ! $option->is_correct && $answer->chosen_option_id === $option->id])><span>{{ $option->text }}@if($answer->chosen_option_id === $option->id) <strong>(sua resposta)</strong>@endif @if($option->is_correct) <strong>· correta</strong>@endif</span></div>@endforeach
@if(! $answer->chosen_option_id)<p class="field-error">Sem resposta.</p>@endif
@if($answer->question->explanation)<p class="quiz-explanation">{{ $answer->question->explanation }}</p>@endif
</fieldset>
@endforeach
@else
<form method="post" action="{{ route('portal.quiz.submit', [$student, $attempt]) }}">@csrf
@foreach($attempt->answers->sortBy('sort_order') as $answer)
@php($options = collect($answer->option_ids)->map(fn ($id) => $answer->question->options->firstWhere('id', $id))->filter())
<fieldset class="card quiz-question"><legend>{{ $loop->iteration }}. {{ $answer->question->statement }}</legend>
@foreach($options as $option)<label class="quiz-answer"><input type="radio" name="answers[{{ $answer->quiz_question_id }}]" value="{{ $option->id }}"><span>{{ $option->text }}</span></label>@endforeach
</fieldset>
@endforeach
<div class="form-actions"><button class="button button-primary">Enviar respostas</button></div></form>
@endif
@endsection
