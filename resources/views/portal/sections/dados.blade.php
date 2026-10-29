@extends('layouts.app')
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Apenas os contatos abaixo podem ser alterados pelo portal.'])
<dl class="card details-grid"><div><dt>Nome</dt><dd>{{ $student->person->full_name }}</dd></div><div><dt>Matrícula</dt><dd>{{ $student->registration_number }}</dd></div><div><dt>E-mail</dt><dd>{{ $student->person->email ?: 'Não informado' }}</dd></div><div><dt>Celular</dt><dd>{{ $student->person->mobile ?: ($student->person->phone ?: 'Não informado') }}</dd></div><div><dt>Contato de emergência</dt><dd>{{ $student->person->emergency_contact_name ?: 'Não informado' }}{{ $student->person->emergency_contact_phone ? ' · '.$student->person->emergency_contact_phone : '' }}</dd></div></dl>
    <section class="card portal-access-card"><div><h2>Senha de acesso</h2><p>Você entra no JUDOMA com {{ auth()->user()->email }}.</p></div><a class="button button-primary" href="{{ route('account.show') }}">Trocar senha</a></section>
    @if($canEditContact)
    <form class="card portal-access-card" method="post" action="{{ route('portal.students.birthday.update', $student) }}">@csrf @method('PUT')<div><h2>Aniversário</h2><p>{{ $student->person->hide_birthday ? 'Escondido: os colegas de turma não recebem aviso no dia. Gestores e professores continuam vendo.' : 'No dia do aniversário, os colegas de turma veem um aviso com o nome e a turma, sem a idade.' }}</p></div><input type="hidden" name="hide_birthday" value="{{ $student->person->hide_birthday ? 0 : 1 }}"><button class="button button-secondary" type="submit">{{ $student->person->hide_birthday ? 'Mostrar aos colegas' : 'Esconder dos colegas' }}</button></form>
    <form class="card entity-form portal-contact" method="post" action="{{ route('portal.students.contact.update', $student) }}">
        @csrf @method('PUT')
        <fieldset><legend>Dados de contato</legend><div class="form-grid">
            <div class="field field-wide"><label for="email">E-mail</label><input id="email" name="email" type="email" value="{{ old('email', $student->person->email) }}">@error('email')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="phone">Telefone</label><input id="phone" name="phone" inputmode="numeric" value="{{ old('phone', $student->person->phone) }}"></div>
            <div class="field"><label for="mobile">Celular</label><input id="mobile" name="mobile" inputmode="numeric" value="{{ old('mobile', $student->person->mobile) }}"></div>
            <div class="field"><label for="emergency_contact_name">Contato de emergência</label><input id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name', $student->person->emergency_contact_name) }}"></div>
            <div class="field"><label for="emergency_contact_phone">Telefone de emergência</label><input id="emergency_contact_phone" name="emergency_contact_phone" inputmode="numeric" value="{{ old('emergency_contact_phone', $student->person->emergency_contact_phone) }}"></div>
        </div></fieldset><div class="form-actions"><button class="button button-primary" type="submit">Salvar contatos</button></div>
    </form>
    @endif
    @unless($canEditContact)<p class="form-help">Somente o próprio aluno ou o responsável legal altera os contatos.</p>@endunless
@include('portal.partials.tabbar', ['current' => 'mais'])
</div>
@endsection
