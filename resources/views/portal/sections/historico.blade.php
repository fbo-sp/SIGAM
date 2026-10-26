@extends('layouts.app')
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Graduações e filiações.'])
<div class="portal-history-grid">@foreach($student->martialArts as $practice)<article class="card portal-history"><h3>{{ $practice->martialArt->name }}</h3><p>Graduação atual: <strong>{{ $practice->currentRank?->display_name ?: 'Não informada' }}</strong></p><p><a href="{{ route('portal.progress', [$student, 'modalidade' => $practice->martial_art_id]) }}">Ver progresso técnico e quiz</a></p><ul>@foreach($practice->rankHistory->sortByDesc('achieved_at') as $rank)<li>{{ $rank->achieved_at->format('d/m/Y') }} — {{ $rank->rank->display_name }}</li>@endforeach</ul></article>@endforeach
@foreach($student->federationMemberships as $membership)<article class="card portal-history"><h3>{{ $membership->federation->name }}</h3><p>{{ $membership->martialArt->name }} · registro {{ $membership->registration_number }}</p><p>Situação: {{ $membership->status === 'active' ? 'Ativa' : 'Inativa' }} · {{ $membership->points }} ponto(s)</p></article>@endforeach</div>
@if($student->martialArts->isEmpty() && $student->federationMemberships->isEmpty())<div class="card empty-state"><p>Nenhum histórico registrado.</p></div>@endif
@include('portal.partials.tabbar', ['current' => 'mais'])
</div>
@endsection
