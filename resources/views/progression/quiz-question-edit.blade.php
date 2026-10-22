@extends('layouts.app')
@section('title', 'Editar pergunta do quiz')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.catalog.index', $association) }}">Modalidades</a><span>/</span><a href="{{ route('associations.quiz.index', [$association, $martialArt, 'graduacao' => $question->rank_id]) }}">Quiz de {{ $martialArt->name }}</a><span>/</span><span>Editar</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · quiz</span><h1>Editar pergunta</h1><p>Depois que a pergunta foi respondida, as alternativas não mudam; inative-a e crie outra.</p></div></div>
<form class="card form-grid" method="post" action="{{ route('associations.quiz.update', [$association, $martialArt, $question]) }}">@csrf @method('PUT')
@include('progression._quiz-form', ['rank' => null])
<div class="form-actions field-wide"><a class="button button-secondary" href="{{ route('associations.quiz.index', [$association, $martialArt, 'graduacao' => $question->rank_id]) }}">Cancelar</a><button class="button button-primary">Salvar</button></div></form>
@endsection
