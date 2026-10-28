@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@php($parts = \App\Http\Controllers\Portal\PortalProgressionController::PARTS)
@php($modality = $options->count() > 1 ? ['modalidade' => $martialArt->id] : [])
@section('title', ($part ? $parts[$part].' — ' : 'Progresso técnico — ').$name)
@section('content')
<div class="portal-page">
@if($part)
<a class="button button-secondary back-link" href="{{ route('portal.progress', [$student, ...$modality]) }}">← Progresso</a>
@else
<a class="button button-secondary back-link" href="{{ route('portal.students.show', $student) }}">← Início</a>
@endif
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · progresso técnico</span><h1>{{ $part ? $parts[$part] : $name }}</h1>
<p>{{ $snapshot['current_rank']?->display_name ?? 'Sem graduação registrada' }}@if($snapshot['next_rank']) · rumo a <strong>{{ $snapshot['next_rank']->display_name }}</strong>@endif</p></div></div>

@if($options->count() > 1)
<nav class="section-tabs" aria-label="Modalidades">@foreach($options as $option)<a href="{{ $part ? route('portal.progress.part', [$student, $part, 'modalidade' => $option->martial_art_id]) : route('portal.progress', [$student, 'modalidade' => $option->martial_art_id]) }}" @if($option->martial_art_id === $martialArt->id) aria-current="page" @endif>{{ $option->martialArt->name }}</a>@endforeach</nav>
@endif

@if($part)
@include('portal.progress.'.$part)
@else
<section class="section-block"><div class="section-heading"><h2>Perfil técnico</h2><p>Seu nível atual por grupo comparado com o pedido para a próxima graduação.</p></div>
@if(! $snapshot['next_rank'])<div class="card empty-state"><p>Você está na última graduação cadastrada; não há currículo de próxima faixa para comparar.</p></div>
@elseif($snapshot['rows']->isEmpty())<div class="card empty-state"><p>Currículo da próxima faixa ainda não definido pela associação.</p></div>
@else @include('portal.progress.radar')
@endif
</section>

@php($missing = $snapshot['missing']->flatten(1)->count())
@php($lastAssessment = $history->first())
<nav class="portal-shortcuts progress-shortcuts" aria-label="Partes do progresso">
<a class="card portal-shortcut {{ $quizRelease ? 'is-highlight' : '' }}" href="{{ route('portal.progress.part', [$student, 'quiz', ...$modality]) }}"><span>{{ $parts['quiz'] }}</span><small>{{ $quizRelease ? 'Prova oficial liberada' : ($snapshot['next_rank'] ? 'Treinar' : 'Ver tentativas') }}</small></a>
<a class="card portal-shortcut" href="{{ route('portal.progress.part', [$student, 'falta', ...$modality]) }}"><span>{{ $parts['falta'] }}</span>@php($counts = $snapshot['statuses'])
@php($summary = collect([$counts['untrained'] ? $counts['untrained'].' não treinado(s)' : null, $counts['improve'] ? $counts['improve'].' a melhorar' : null, $counts['awaiting'] && ! $counts['untrained'] && ! $counts['improve'] ? $counts['awaiting'].' aguardando avaliação' : null])->filter()->join(' · '))
<small>{{ ! $snapshot['next_rank'] ? 'Última graduação' : ($snapshot['rows']->isEmpty() ? 'Currículo não definido' : ($missing ? $summary : 'Tudo no nível pedido')) }}</small></a>
<a class="card portal-shortcut" href="{{ route('portal.progress.part', [$student, 'aulas', ...$modality]) }}"><span>{{ $parts['aulas'] }}</span><small>{{ $worked['sessions'] }} aula(s) em 90 dias</small></a>
<a class="card portal-shortcut" href="{{ route('portal.progress.part', [$student, 'evolucao', ...$modality]) }}"><span>{{ $parts['evolucao'] }}</span><small>{{ $lastAssessment ? 'Última avaliação '.$lastAssessment->assessed_on->format('d/m') : 'Nenhuma avaliação' }}</small></a>
<a class="card portal-shortcut" href="{{ route('portal.study', [$student, ...$modality]) }}"><span>Estudar</span><small>Vídeos e fichas das técnicas</small></a>
</nav>
@endif
@include('portal.partials.tabbar', ['current' => 'mais'])
</div>
@endsection
