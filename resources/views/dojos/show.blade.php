@extends('layouts.app')
@section('title', $dojo->name.' — JUDOMA')
@section('content')
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><a href="{{ route('associations.dojos.index', $association) }}">Dojos</a><span>/</span><span>{{ $dojo->name }}</span></nav>
<div class="page-heading"><div><span class="eyebrow">Dojo {{ $dojo->code }}</span><h1>{{ $dojo->name }}</h1><p>{{ $dojo->city ? $dojo->city.'/'.$dojo->state : 'Endereço ainda não informado' }}</p></div>@if(auth()->user()->hasPermission('dojo.manage', $association->id))<a class="button button-secondary" href="{{ route('associations.dojos.edit', [$association, $dojo]) }}">Editar dojo</a>@endif</div>
<dl class="card details-grid"><div><dt>Situação</dt><dd>{{ $dojo->status === 'active' ? 'Ativo' : 'Inativo' }}</dd></div><div><dt>Início</dt><dd>{{ $dojo->started_at?->format('d/m/Y') ?? 'Não informado' }}</dd></div><div><dt>Capacidade</dt><dd>{{ $dojo->capacity ?? 'Não informada' }}</dd></div><div><dt>Contato</dt><dd>{{ $dojo->email ?: ($dojo->phone ?: 'Não informado') }}</dd></div><div class="field-wide"><dt>Endereço</dt><dd>{{ collect([$dojo->street, $dojo->number, $dojo->district, $dojo->city, $dojo->state])->filter()->join(', ') ?: 'Não informado' }}</dd></div></dl>
@endsection
