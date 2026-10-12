@extends('layouts.app')
@section('title', 'Acessos aos dojos — '.$association->trade_name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><span>Acessos aos dojos</span></nav>
<div class="page-heading"><div><span class="eyebrow">Segurança operacional</span><h1>Acessos aos dojos</h1><p>Escolha a conta, a função, a unidade e a vigência. O acesso não vale para outros dojos.</p></div></div>
<div class="alert alert-warning"><strong>Atenção:</strong> o papel “Professor do dojo” somente pode ser concedido a uma conta vinculada a professor ativo da associação. Ele não permite reabrir chamadas nem acessar documentos ou financeiro.</div>
<details class="card disclosure" @if($errors->any()) open @endif><summary>Conceder acesso</summary>
<form method="post" action="{{ route('dojo-access.store', $association) }}" class="context-form">@csrf
    <div class="field"><label for="user_id">Conta</label><select id="user_id" name="user_id" required><option value="">Selecione</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} — {{ $user->email }}</option>@endforeach</select></div>
    <div class="field"><label for="dojo_id">Dojo</label><select id="dojo_id" name="dojo_id" required><option value="">Selecione</option>@foreach($dojos as $dojo)<option value="{{ $dojo->id }}" @selected(old('dojo_id') == $dojo->id)>{{ $dojo->name }}</option>@endforeach</select></div>
    <div class="field"><label for="role_id">Função</label><select id="role_id" name="role_id" required><option value="">Selecione</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>@endforeach</select></div>
    <div class="field"><label for="start_date">Início</label><input id="start_date" name="start_date" type="date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required></div>
    <div class="field"><label for="end_date">Término, se houver</label><input id="end_date" name="end_date" type="date" value="{{ old('end_date') }}"></div>
    <button class="button button-primary">Conceder acesso</button>
</form></details>
<section class="section-block"><div class="section-heading"><h2>Histórico de concessões</h2><p>Revogar preserva o histórico e tem efeito imediato.</p></div>
<div class="card list-card">
@forelse($grants as $grant)
    <div class="list-row context-row"><span><strong>{{ $grant->user->name }} · {{ $grant->dojo->name }}</strong><small>{{ $grant->role->name }} · {{ $grant->start_date->format('d/m/Y') }} até {{ $grant->end_date?->format('d/m/Y') ?? 'sem data final' }} · {{ $grant->revoked_at ? 'Revogado' : 'Vigente conforme período' }}</small></span>@if(!$grant->revoked_at)<form method="post" action="{{ route('dojo-access.revoke', [$association, $grant]) }}">@csrf<button class="button button-secondary">Revogar</button></form>@endif</div>
@empty
    <div class="empty-state"><h2>Nenhuma concessão registrada</h2><p>As contas não recebem acesso local automaticamente.</p></div>
@endforelse
</div>{{ $grants->links() }}</section>
@endsection
