@extends('layouts.app')
@section('title', 'Meus dojos — JUDOMA')
@section('content')
<div class="page-heading"><div><span class="eyebrow">Contexto de trabalho</span><h1>Meus dojos</h1><p>Escolha a unidade em que você vai trabalhar. Cada acesso respeita sua função e o período autorizado.</p></div></div>
<div class="card list-card">
@forelse($dojos as $dojo)
    <a class="list-row" href="{{ route('operation.home', $dojo) }}"><span><strong>{{ $dojo->name }}</strong><small>{{ $dojo->association->trade_name }}@if($dojo->city) · {{ $dojo->city }}/{{ $dojo->state }}@endif</small></span><span aria-hidden="true">›</span></a>
@empty
    <div class="empty-state"><h2>Nenhum dojo disponível</h2><p>Peça ao gestor da associação para conferir sua função, o período e a unidade autorizada.</p></div>
@endforelse
</div>
{{ $dojos->links() }}
@endsection
