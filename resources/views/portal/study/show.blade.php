@extends('layouts.app')
@php($row = $entry['row'])
@php($embed = $item->videoEmbedUrl())
@section('title', $item->name.' — Estudar')
@section('content')
<div class="portal-page study-page">
<a class="button button-secondary back-link" href="{{ route('portal.study', [$student, 'modalidade' => $martialArt->id]) }}">← Estudar</a>
<div class="study-layout">
<div class="study-main">
@if($embed)<div class="study-video"><iframe src="{{ $embed }}" title="Vídeo: {{ $item->name }}" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div><p class="study-video-out"><a href="{{ $item->video_url }}" target="_blank" rel="noopener noreferrer">Abrir o vídeo no {{ str_contains((string) $item->video_url, 'vimeo') ? 'Vimeo' : 'YouTube' }}</a></p>
@elseif($item->video_url)<a class="card study-video-link" href="{{ $item->video_url }}" target="_blank" rel="noopener noreferrer">@include('portal.partials.icon', ['name' => 'video'])<span>Abrir vídeo da técnica</span></a>@endif
@include('portal.study.partials.head')
@if($row && $row['tip'])<div class="card study-callout"><strong>Dica do professor{{ $row['assessed_on'] ? ' · '.$row['assessed_on']->format('d/m') : '' }}</strong><p>{{ $row['tip'] }}</p></div>@endif
@include('portal.study.partials.content')
<div class="study-done-block"><form method="post" action="{{ route('portal.study.studied', [$student, $item]) }}">@csrf<button class="button button-primary study-done" @disabled($studiedToday)>{{ $studiedToday ? 'Estudo de hoje registrado' : 'Estudei esta técnica' }}</button></form><p class="form-help">O professor vê na sua ficha de acompanhamento que você estudou em casa. Não muda a avaliação.</p></div>
@if(! $item->hasStudyContent())<div class="card empty-state"><p>A associação ainda não incluiu vídeo nem conteúdo de estudo para este item. Pergunte ao professor na próxima aula.</p></div>@endif
</div>
<aside class="study-side">
<div class="card study-situation"><h2 class="study-label">Sua situação</h2>
@if($row)<dl class="study-status"><div><dt>Nível atual</dt><dd>{{ $levels[$row['current'] ?? 1] }}</dd></div><div><dt>Pedido em {{ $snapshot['next_rank']->display_name }}</dt><dd>{{ $levels[$row['level']] }}</dd></div><div><dt>Último treino em aula</dt><dd>{{ $row['trained_on']?->format('d/m/Y') ?? 'Ainda não' }}</dd></div><div><dt>Avaliações</dt><dd>{{ $assessments }}</dd></div></dl>
@else<p class="form-help">Este item não está no currículo da sua próxima faixa. Estude à vontade: ele aparece {{ $entry['first_rank'] ? 'a partir de '.$entry['first_rank']->display_name : 'no catálogo da associação' }}.</p>@endif
@if($entry['studied'])<p class="form-help">Você estudou {{ $entry['studied']['count'] }}x no portal, a última em {{ $entry['studied']['last']->format('d/m/Y') }}.</p>@endif
</div>
@if($related->isNotEmpty())<div class="card list-card"><h2 class="study-label">Veja também</h2>@foreach($related as $other)<a class="list-row study-row" href="{{ route('portal.study.item', [$student, $other['item']]) }}"><span class="study-row-text"><strong>{{ $other['item']->name }}</strong>@if($other['item']->translation)<small>{{ $other['item']->translation }}</small>@endif</span></a>@endforeach</div>@endif
</aside>
</div>
@include('portal.partials.tabbar', ['current' => 'estudar'])
</div>
@endsection
