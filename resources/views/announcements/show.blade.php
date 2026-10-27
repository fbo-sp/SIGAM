@extends('layouts.app')
@section('title', $announcement->title.' — JUDOMA')
@php($roles = \App\Services\Notifications\AnnouncementService::ROLES)
@php($emailLabels = ['none' => '', 'pending' => 'e-mail na fila', 'sending' => 'e-mail enviando', 'sent' => 'e-mail enviado', 'failed' => 'e-mail com falha, nova tentativa', 'suppressed' => 'e-mail cancelado'])
@section('content')
@include('announcements.partials.crumbs', ['current' => $announcement->title])
<section class="page-heading"><div><span class="eyebrow">{{ $announcement->sent_at->format('d/m/Y H:i') }} · {{ $announcement->sender->name }}</span><h1>{{ $announcement->title }}</h1><p>@include('announcements.partials.target') · {{ $announcement->recipient_count }} pessoa(s)</p></div><a class="button button-secondary" href="{{ route($prefix.'.index', $context) }}">Todos os avisos</a></section>
<section class="portal-indicators announcement-stats"><article class="card portal-indicator"><span>Pessoas</span><strong>{{ $stats['total'] }}</strong><small>{{ $stats['accounts'] }} com conta</small></article><article class="card portal-indicator"><span>Leram no JUDOMA</span><strong>{{ $stats['read'] }}</strong><small>de {{ $stats['accounts'] }} com conta</small></article><article class="card portal-indicator"><span>E-mails enviados</span><strong>{{ $stats['emails_sent'] }}</strong><small>de {{ $stats['emails'] }}</small></article></section>
<article class="card announcement-message"><p class="announcement-body">{{ $announcement->body }}</p><small>{{ app(\App\Services\Notifications\AnnouncementService::class)->signature($announcement->sender, $announcement->dojo, $announcement->sender_level) }}</small></article>
<section class="card list-card section-block" aria-label="Destinatários">
@foreach($announcement->recipients->sortBy(fn ($row) => $row->person->social_name ?: $row->person->full_name) as $recipient)
<div class="list-row"><span><strong>{{ $recipient->person->social_name ?: $recipient->person->full_name }}</strong><small>{{ $roles[$recipient->role] ?? $recipient->role }}@if($emailLabels[$recipient->email_status] ?? '') · {{ $emailLabels[$recipient->email_status] }}@endif</small></span><span @class(['status-pill', 'status-inactive' => ! $recipient->notification?->read_at])>{{ $recipient->notification_id ? ($recipient->notification?->read_at ? 'Leu' : 'Não leu') : 'Sem conta' }}</span></div>
@endforeach
</section>
@endsection
