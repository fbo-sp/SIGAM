@extends('layouts.app')
@section('title', 'Ativar conta — JUDOMA')
@section('content')
<section class="auth-shell"><div class="auth-intro"><span class="eyebrow">Primeiro acesso</span><h1>Ative sua conta</h1><p>Crie sua senha com pelo menos 12 caracteres, com letras e números.</p></div><form class="card form-stack" method="post" action="{{ route('account-activation.store', $token) }}">@csrf
@if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<span>{{ $error }}</span>@endforeach</div>@endif
<div><label for="email">E-mail</label><input type="email" id="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required></div><div><label for="password">Senha</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required></div><div><label for="password_confirmation">Repita a senha</label><input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div><button class="button button-primary button-block">Ativar conta</button></form></section>
@endsection
