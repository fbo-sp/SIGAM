@extends('layouts.app')
@section('title', 'Convite de professor — JUDOMA')
@section('content')
<section class="auth-shell" aria-labelledby="invitation-title"><div class="auth-intro"><span class="eyebrow">Convite institucional</span><h1 id="invitation-title">Acesso como professor</h1><p><strong>{{ $invitation->association->trade_name }}</strong> convidou {{ $invitation->teacher->person->social_name ?: $invitation->teacher->person->full_name }} para usar o JUDOMA.</p><p>Abrir esta página não verificou seu e-mail nem aceitou o convite.</p></div>
<div class="card form-stack">
@php($state = $invitation->displayState())
@if(in_array($state, ['pending','sent']))
<p class="alert alert-info">O convite é válido até {{ $invitation->expires_at->format('d/m/Y H:i') }}.</p>
<form class="form-stack compact-form" method="post" action="{{ route('teacher-invitations.accept', ['token' => $token]) }}">@csrf
@if($existingAccount)<div><label for="current_password">Senha atual</label><input type="password" id="current_password" name="current_password" autocomplete="current-password" required><small>Esta conta já existe. Sua senha e seus outros papéis não serão alterados.</small></div>
@else<div><label for="password">Crie sua senha</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required><small>Use pelo menos 12 caracteres, com letras e números.</small></div><div><label for="password_confirmation">Repita a senha</label><input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>@endif
<label class="check-row"><input type="checkbox" name="accept_terms" value="1" required>Confirmo que quero aceitar o convite e ativar meu acesso como professor.</label><button class="button button-primary button-block">Aceitar e ativar acesso</button></form>
<form method="post" action="{{ route('teacher-invitations.decline', ['token' => $token]) }}">@csrf<input type="hidden" name="decline_confirmation" value="1"><button class="button button-secondary button-block">Recusar convite</button></form>
@else<div class="alert alert-warning">{{ ['accepted'=>'Este convite já foi aceito.','declined'=>'Este convite foi recusado.','revoked'=>'Este convite foi revogado.','expired'=>'Este convite expirou.'][$state] ?? 'Este convite não está disponível.' }}</div><a class="button button-primary button-block" href="{{ route('login') }}">Ir para o login</a>@endif
</div></section>
@endsection
