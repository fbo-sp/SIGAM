@extends('layouts.app')
@section('title', 'Avisos — JUDOMA')
@section('content')
@include('announcements.partials.crumbs')
<section class="page-heading"><div><span class="eyebrow">{{ $dojo?->name ?? $association->trade_name }}</span><h1>Avisos</h1><p>{{ match ($level) { 'association' => 'Avisos enviados pela associação, pelos dojos e pelos professores.', 'dojo' => 'Avisos enviados neste dojo, inclusive pelos professores.', default => 'Avisos que você enviou às suas turmas e alunos.' } }}</p></div><a class="button button-primary" href="{{ route($prefix.'.create', $context) }}">Enviar aviso</a></section>
<section class="card list-card">
@forelse($announcements as $announcement)
<a class="list-row" href="{{ route($prefix.'.show', [$context, $announcement]) }}"><span><strong>{{ $announcement->title }}</strong><small>{{ $announcement->sent_at->format('d/m/Y H:i') }} · {{ $announcement->sender->name }}@if($level === 'association' && $announcement->dojo) · {{ $announcement->dojo->name }}@endif · @include('announcements.partials.target') · {{ $announcement->recipient_count }} pessoa(s)</small></span><span class="status-pill">Ver</span></a>
@empty
<div class="empty-state"><h2>Nenhum aviso enviado</h2><p>Use "Enviar aviso" para falar com turmas, alunos e responsáveis.</p></div>
@endforelse
</section>
{{ $announcements->links() }}
@endsection
