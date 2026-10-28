@extends('layouts.app')
@php($modality = $options->count() > 1 ? ['modalidade' => $martialArt->id] : [])
@section('title', 'Estudar — JUDOMA')
@section('content')
@include('partials.back-to-dashboard')
<div class="page-heading"><div><span class="eyebrow">Professor · {{ $martialArt->name }}</span><h1>Estudar</h1><p>Consulta do material de estudo do catálogo: vídeo, explicação, pontos avaliados, cuidado, segurança e ficha.</p></div></div>
@if($options->count() > 1)
<nav class="section-tabs" aria-label="Modalidades">@foreach($options as $option)<a href="{{ route('teacher.study', ['modalidade' => $option->id]) }}" @if($option->id === $martialArt->id) aria-current="page" @endif>{{ $option->name }}</a>@endforeach</nav>
@endif
<form class="study-search" method="get" action="{{ route('teacher.study') }}">@if($modality)<input type="hidden" name="modalidade" value="{{ $martialArt->id }}">@endif<label class="sr-only" for="q">Procurar técnica</label><input id="q" name="q" type="search" value="{{ $term }}" placeholder="Procurar técnica (ex.: ukemi)" maxlength="80"><button class="button button-secondary">Procurar</button></form>
@if($ranks->isNotEmpty())
<nav class="filter-pills study-filters" aria-label="Faixa em que entra no currículo"><a href="{{ route('teacher.study', $modality) }}" @if(! $rank && $term === '') aria-current="page" @endif>Todo o catálogo</a>@foreach($ranks as $option)<a href="{{ route('teacher.study', ['faixa' => $option->id, ...$modality]) }}" @if($rank?->id === $option->id) aria-current="page" @endif>{{ $option->display_name }}</a>@endforeach</nav>
@endif
@if($term !== '')<p class="progress-total">Resultado para "{{ $term }}" em todo o catálogo.</p>@elseif($rank)<p class="progress-total">Itens que entram no currículo em {{ $rank->display_name }}.</p>@endif
@forelse($groups as $group => $rows)
<section class="section-block study-group"><h2 class="study-group-title">{{ $group }}</h2>
<div class="card list-card">@foreach($rows as $row)
<a class="list-row study-row" href="{{ route('teacher.study.item', $row['item']) }}"><span @class(['study-thumb', 'has-video' => $row['item']->video_url])>@include('portal.partials.icon', ['name' => $row['item']->video_url ? 'video' : 'estudar'])</span><span class="study-row-text"><strong>{{ $row['item']->name }}</strong>@if($row['item']->translation)<small>{{ $row['item']->translation }}</small>@endif</span>@if($row['first_rank'])<span class="study-pill">{{ $row['first_rank']->display_name }}</span>@endif</a>
@endforeach</div></section>
@empty
<div class="card empty-state"><p>{{ $term !== '' ? 'Nenhuma técnica com esse nome.' : 'Nada para mostrar neste filtro.' }}</p></div>
@endforelse
@endsection
