@extends('layouts.app')
@section('title', 'Recuperar acesso — JUDOMA')
@section('content')
<section class="auth-shell"><div class="auth-intro"><span class="eyebrow">Recuperação segura</span><h1>Esqueceu sua senha?</h1><p>Informe seu e-mail. Se houver uma conta ativa, você receberá um link válido por 60 minutos.</p></div><form class="card form-stack" method="post" action="{{ route('password.email') }}">@csrf<div><label for="email">E-mail</label><input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></div><button class="button button-primary button-block">Enviar instruções</button><a class="button button-secondary button-block" href="{{ route('login') }}">Voltar ao login</a></form></section>
@endsection
