@extends('layouts.app')
@section('title', 'Nova conta — '.$association->trade_name)
@section('content')
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><a href="{{ route('associations.users.index', $association) }}">Usuários</a><span>/</span><span>Nova conta</span></nav>
<div class="page-heading"><div><span class="eyebrow">Segurança</span><h1>Nova conta</h1><p>A conta fica ligada a uma pessoa do cadastro central. A pessoa recebe um convite por e-mail e define a própria senha.</p></div></div>
@if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<span>{{ $error }}</span>@endforeach</div>@endif
@if(! $selected)
<form class="card filter-bar" method="get"><div class="field filter-grow"><label for="q">Buscar pessoa sem conta</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Nome ou e-mail"></div>@if($preset)<input type="hidden" name="perfil" value="{{ $preset[0] }}">@endif<button class="button button-secondary" type="submit">Buscar</button></form>
<div class="card list-card">@forelse($people as $person)<a class="list-row" href="{{ route('associations.users.create', ['association' => $association, 'person_id' => $person->id, 'perfil' => $preset[0] ?? null]) }}"><span><strong>{{ $person->full_name }}</strong><small>{{ $person->email ?: 'E-mail não informado' }}</small></span><span>Escolher</span></a>@empty<div class="empty-state"><h2>Nenhuma pessoa disponível</h2><p>Só aparecem pessoas ativas na associação que ainda não têm conta.</p></div>@endforelse</div>
@else
<form class="card entity-form" method="post" action="{{ route('associations.users.store', $association) }}">@csrf<input type="hidden" name="person_id" value="{{ $selected->id }}">
<fieldset><legend>Pessoa</legend><div class="form-grid"><div class="field"><label>Nome</label><p>{{ $selected->full_name }} · <a href="{{ route('associations.users.create', $association) }}">trocar</a></p></div><div class="field"><label for="email">E-mail de acesso</label><input id="email" type="email" name="email" value="{{ old('email', $selected->email) }}" autocomplete="off" required><small>O convite será enviado para este e-mail.</small></div></div></fieldset>
<fieldset><legend>Perfis</legend><div class="form-stack" style="padding:0">@foreach($definitions as $code => $definition)@php($allowed = in_array($code, $grantable, true))<label class="check-row"><input type="checkbox" name="roles[]" value="{{ $code }}" @checked(in_array($code, old('roles', $preset), true)) @disabled(! $allowed)><span><strong>{{ $definition['name'] }}</strong><br>{{ $definition['description'] }}@unless($allowed)<br><em>Você não pode conceder este perfil.</em>@endunless</span></label>@endforeach</div></fieldset>
<div class="form-actions"><a class="button button-secondary" href="{{ route('associations.users.index', $association) }}">Cancelar</a><button class="button button-primary">Criar conta e enviar convite</button></div>
</form>
@endif
@endsection
