@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($modality = $options->count() > 1 ? ['modalidade' => $martialArt->id] : [])
@php($pills = ['improve' => ['Melhorar', 'is-improve'], 'untrained' => ['Não treinada', 'is-untrained'], 'awaiting' => ['Aguardando avaliação', 'is-awaiting'], 'ok' => ['No nível', 'is-ok']])
@section('title', 'Estudar — '.$name)
@section('content')
<div class="portal-page">
<a class="button button-secondary back-link" href="{{ route('portal.students.show', $student) }}">← Início</a>
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · {{ $snapshot['current_rank']?->display_name ?? 'sem graduação registrada' }}</span><h1>Estudar</h1><p>Vídeos, pontos avaliados e fichas das técnicas e conhecimentos do catálogo.</p></div></div>
@if($options->count() > 1)
<nav class="section-tabs" aria-label="Modalidades">@foreach($options as $option)<a href="{{ route('portal.study', [$student, 'modalidade' => $option->martial_art_id]) }}" @if($option->martial_art_id === $martialArt->id) aria-current="page" @endif>{{ $option->martialArt->name }}</a>@endforeach</nav>
@endif
<form class="study-search" method="get" action="{{ route('portal.study', $student) }}">@if($modality)<input type="hidden" name="modalidade" value="{{ $martialArt->id }}">@endif<label class="sr-only" for="q">Procurar técnica</label><input id="q" name="q" type="search" value="{{ $term }}" placeholder="Procurar técnica (ex.: ukemi)" maxlength="80"><button class="button button-secondary">Procurar</button></form>
<nav class="filter-pills study-filters" aria-label="Filtros">@foreach(\App\Services\Progression\TechniqueStudyService::FILTERS as $key => $label)<a href="{{ route('portal.study', [$student, 'filtro' => $key, ...$modality]) }}" @if($filter === $key) aria-current="page" @endif>{{ $label }}@if($key !== 'todas') · {{ $counts[$key] }}@endif</a>@endforeach</nav>
@if($filter === 'melhorar' && $counts['melhorar'] > 0)<div class="card study-callout"><strong>O professor pediu atenção em {{ $counts['melhorar'] }} item(ns).</strong><p>Comece por eles. A dica dele aparece dentro de cada um.</p></div>@endif
@if($term !== '')<p class="progress-total">Resultado para "{{ $term }}" em todo o catálogo.</p>@endif
@forelse($groups as $group => $rows)
<section class="section-block study-group"><h2 class="study-group-title">{{ $group }}</h2>
<div class="card list-card">@foreach($rows as $row)@php($status = $row['row']['status'] ?? null)
<a class="list-row study-row" href="{{ route('portal.study.item', [$student, $row['item']]) }}"><span @class(['study-thumb', 'has-video' => $row['item']->video_url])>@include('portal.partials.icon', ['name' => $row['item']->video_url ? 'video' : 'estudar'])</span><span class="study-row-text"><strong>{{ $row['item']->name }}</strong>@if($row['item']->translation)<small>{{ $row['item']->translation }}</small>@endif @if($row['studied'])<small>Estudou {{ $row['studied']['count'] }}x · última em {{ $row['studied']['last']->format('d/m') }}</small>@endif</span>@if($status)<span class="study-pill {{ $pills[$status][1] }}">{{ $pills[$status][0] }}</span>@elseif($row['first_rank'])<span class="study-pill">{{ $row['first_rank']->display_name }}</span>@endif</a>
@endforeach</div></section>
@empty
<div class="card empty-state"><p>{{ $term !== '' ? 'Nenhuma técnica com esse nome.' : ($filter === 'melhorar' ? 'Nenhum pedido de melhora do professor agora.' : ($filter === 'aprendidas' ? 'Ainda não há item avaliado no nível pedido.' : 'Nada para mostrar neste filtro.')) }}</p></div>
@endforelse
@include('portal.partials.tabbar', ['current' => 'estudar'])
</div>
@endsection
