@extends('layouts.app')
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Os arquivos continuam privados e cada download é registrado.'])
<div class="portal-list">@forelse($student->documents as $document)<article class="card portal-row"><div><span class="status-pill">{{ $document->type->name }}</span><h3>{{ $document->original_name }}</h3><p>{{ $document->expiration_date ? 'Validade '.$document->expiration_date->format('d/m/Y') : 'Sem vencimento informado' }}</p></div><a class="button button-secondary" href="{{ route('portal.students.documents.download', [$student, $document]) }}">Baixar</a></article>@empty<div class="card empty-state"><p>Nenhum documento disponível.</p></div>@endforelse</div>
@include('portal.partials.tabbar', ['current' => 'mais'])
</div>
@endsection
