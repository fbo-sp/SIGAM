@extends('layouts.app')

@section('title', 'Entrar — JUDOMA')

@section('content')
<section class="auth-shell" aria-labelledby="login-title">
    <div class="auth-intro">
        <span class="eyebrow">Gestão simples e segura</span>
        <h1 id="login-title">Bem-vindo ao JUDOMA</h1>
        <p>Acesse para organizar alunos, turmas, chamadas e atividades da associação.</p>
    </div>

    <form class="card form-stack" method="post" action="{{ route('login.store') }}" novalidate>
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" autocomplete="username" inputmode="email"
                   value="{{ old('email') }}" required autofocus aria-describedby="email-error">
            @error('email')<p id="email-error" class="field-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   aria-describedby="password-error">
            @error('password')<p id="password-error" class="field-error">{{ $message }}</p>@enderror
        </div>

        <label class="check-row">
            <input name="remember" type="checkbox" value="1">
            <span>Continuar conectado neste dispositivo</span>
        </label>

        <button class="button button-primary button-block" type="submit">Entrar</button>
        <a class="button button-secondary button-block" href="{{ route('password.request') }}">Esqueci minha senha</a>
        <p class="form-help">Problemas para acessar? Procure o responsável pela sua associação.</p>
    </form>
</section>
@endsection

