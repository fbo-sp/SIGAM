@extends('layouts.app')
@section('title', $account->name.' — Usuários')
@section('content')
@php($statusLabels = ['active' => 'Ativa', 'pending' => 'Aguardando ativação', 'inactive' => 'Inativa'])
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><a href="{{ route('associations.users.index', $association) }}">Usuários</a><span>/</span><span>{{ $account->name }}</span></nav>
<div class="page-heading"><div><span class="eyebrow">Conta de acesso</span><h1>{{ $account->name }}</h1><p>{{ $account->email }}</p></div><span class="status-pill {{ $account->status !== 'active' ? 'status-inactive' : '' }}">{{ $statusLabels[$account->status] ?? $account->status }}</span></div>
@if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<span>{{ $error }}</span>@endforeach</div>@endif
<dl class="card details-grid"><div><dt>Pessoa</dt><dd>{{ $account->person?->full_name ?? 'Conta sem pessoa vinculada' }}</dd></div><div><dt>Último acesso</dt><dd>{{ $account->last_login_at?->format('d/m/Y H:i') ?? 'Nunca entrou' }}</dd></div></dl>
@if($systemAccount)
<div class="alert alert-info">Esta é uma conta de administrador do sistema e não pode ser alterada por esta tela.</div>
@else
<section class="section-block"><div class="section-heading"><h2>Perfis na associação</h2><p>Acessos por dojo são concedidos em @if(auth()->user()->hasPermission('role.manage', $association->id))<a href="{{ route('dojo-access.index', $association) }}">Acessos aos dojos</a>@else Acessos aos dojos @endif.</p></div>
<form class="card form-stack" method="post" action="{{ route('associations.users.roles.update', [$association, $account]) }}">@csrf @method('PUT')
@foreach($definitions as $code => $definition)@php($allowed = in_array($code, $grantable, true))
<label class="check-row"><input type="checkbox" name="roles[]" value="{{ $code }}" @checked(in_array($code, old('roles', $current), true)) @disabled(! $allowed)>@if(! $allowed && in_array($code, $current, true))<input type="hidden" name="roles[]" value="{{ $code }}">@endif<span><strong>{{ $definition['name'] }}</strong><br>{{ $definition['description'] }}@unless($allowed)<br><em>Você não pode conceder nem retirar este perfil.</em>@endunless</span></label>
@endforeach
<div><button class="button button-primary">Salvar perfis</button></div></form></section>
<section class="section-block"><div class="section-heading"><h2>Situação da conta</h2><p>Inativar encerra as sessões abertas na hora. O histórico é preservado.</p></div><div class="inline-form">
@if($awaitsActivation)<form method="post" action="{{ route('associations.users.invitation.resend', [$association, $account]) }}">@csrf<button class="button button-secondary">Reenviar convite</button></form>@endif
@if($account->status !== 'inactive' && ! $isSelf)<form method="post" action="{{ route('associations.users.deactivate', [$association, $account]) }}" onsubmit="return confirm('Inativar esta conta?')">@csrf<button class="button button-secondary">Inativar conta</button></form>@endif
@if($account->status === 'inactive')<form method="post" action="{{ route('associations.users.reactivate', [$association, $account]) }}">@csrf<button class="button button-primary">Reativar conta</button></form>@endif
</div></section>
@endif
@endsection
