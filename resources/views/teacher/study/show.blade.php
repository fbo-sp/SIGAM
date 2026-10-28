@extends('layouts.app')
@php($embed = $item->videoEmbedUrl())
@section('title', $item->name.' — Estudar')
@section('content')
<div class="study-page">
<a class="button button-secondary back-link" href="{{ route('teacher.study', ['modalidade' => $martialArt->id]) }}">← Estudar</a>
<div class="study-layout">
<div class="study-main">
@if($embed)<div class="study-video"><iframe src="{{ $embed }}" title="Vídeo: {{ $item->name }}" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div><p class="study-video-out"><a href="{{ $item->video_url }}" target="_blank" rel="noopener noreferrer">Abrir o vídeo no {{ str_contains((string) $item->video_url, 'vimeo') ? 'Vimeo' : 'YouTube' }}</a></p>
@elseif($item->video_url)<a class="card study-video-link" href="{{ $item->video_url }}" target="_blank" rel="noopener noreferrer">@include('portal.partials.icon', ['name' => 'video'])<span>Abrir vídeo da técnica</span></a>@endif
@include('portal.study.partials.head')
@include('portal.study.partials.content')
@if(! $item->hasStudyContent())<div class="card empty-state"><p>A associação ainda não incluiu vídeo nem conteúdo de estudo para este item.</p></div>@endif
</div>
<aside class="study-side">
<div class="card study-curriculum"><h2 class="study-label">No currículo</h2>
@if($curriculum->isNotEmpty())<dl class="study-status">@foreach($curriculum as $entryRank)<div><dt>{{ $entryRank['rank']->display_name }}</dt><dd>{{ $entryRank['level'] === null ? 'Deixa de ser cobrado' : $levels[$entryRank['level']] }}</dd></div>@endforeach</dl>
@else<p class="form-help">Este item não é cobrado no currículo de nenhuma faixa.</p>@endif
</div>
@if($related->isNotEmpty())<div class="card list-card"><h2 class="study-label">Veja também</h2>@foreach($related as $other)<a class="list-row study-row" href="{{ route('teacher.study.item', $other['item']) }}"><span class="study-row-text"><strong>{{ $other['item']->name }}</strong>@if($other['item']->translation)<small>{{ $other['item']->translation }}</small>@endif</span></a>@endforeach</div>@endif
</aside>
</div>
</div>
@endsection
