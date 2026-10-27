@extends('layouts.app')
@section('title', 'Minha conta — JUDOMA')
@section('content')
<header class="page-heading"><div><span class="eyebrow">Minha conta</span><h1>{{ $user->name }}</h1><p>{{ $user->email }}</p></div></header>
<form class="card form-stack account-password" method="post" action="{{ route('account.password.update') }}">
    @csrf
    @method('put')
    <h2>Trocar senha</h2>
    <p class="form-help account-help">Use pelo menos 12 caracteres, com letras e números. Depois da troca, os outros aparelhos conectados precisam entrar de novo.</p>
    <div><label for="current_password">Senha atual</label><input type="password" id="current_password" name="current_password" autocomplete="current-password" required>@error('current_password')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div><label for="password">Nova senha</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required>@error('password')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div><label for="password_confirmation">Repita a nova senha</label><input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
    <button class="button button-primary button-block" type="submit">Trocar senha</button>
    <p class="form-help">Esqueceu a senha atual? Saia e use "Esqueci minha senha" na tela de entrada.</p>
</form>
@endsection
