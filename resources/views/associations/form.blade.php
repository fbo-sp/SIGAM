@extends('layouts.app')

@php($editing = $association->exists)
@section('title', ($editing ? 'Editar' : 'Nova').' associação — JUDOMA')

@section('content')
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="{{ route('associations.index') }}">Associações</a><span>/</span><span>{{ $editing ? 'Editar' : 'Nova' }}</span></nav>
<div class="page-heading"><div><span class="eyebrow">Organização</span><h1>{{ $editing ? 'Editar associação' : 'Nova associação' }}</h1><p>Comece pelos dados principais. Os demais campos podem ser completados depois.</p></div></div>

<form class="card entity-form" method="post" action="{{ $editing ? route('associations.update', $association) : route('associations.store') }}">
    @csrf @if($editing) @method('put') @endif
    <fieldset><legend>Identificação</legend><div class="form-grid">
        <div class="field field-wide"><label for="trade_name">Nome usado no dia a dia</label><input id="trade_name" name="trade_name" value="{{ old('trade_name', $association->trade_name) }}" required maxlength="255" autofocus>@error('trade_name')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div class="field field-wide"><label for="legal_name">Razão social</label><input id="legal_name" name="legal_name" value="{{ old('legal_name', $association->legal_name) }}" required maxlength="255">@error('legal_name')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="cnpj">CNPJ</label><input id="cnpj" name="cnpj" inputmode="numeric" value="{{ old('cnpj', $association->cnpj) }}" aria-describedby="cnpj-help">@error('cnpj')<p class="field-error">{{ $message }}</p>@enderror<small id="cnpj-help">Somente números ou com pontuação.</small></div>
        <div class="field"><label for="foundation_date">Data de fundação</label><input type="date" id="foundation_date" name="foundation_date" value="{{ old('foundation_date', $association->foundation_date?->format('Y-m-d')) }}">@error('foundation_date')<p class="field-error">{{ $message }}</p>@enderror</div>
    </div></fieldset>
    @include('associations.partials.contact-address-fields')
    <fieldset><legend>Situação e regras</legend><div class="form-grid"><div class="field"><label for="status">Situação</label><select id="status" name="status"><option value="active" @selected(old('status', $association->status ?: 'active') === 'active')>Ativa</option><option value="inactive" @selected(old('status', $association->status) === 'inactive')>Inativa</option></select></div><div class="field"><label for="requires_minor_guardian">Ativação de aluno menor</label><select id="requires_minor_guardian" name="requires_minor_guardian"><option value="1" @selected((string) old('requires_minor_guardian', $association->exists ? (int) $association->requires_minor_guardian : 1) === '1')>Exigir responsável legal</option><option value="0" @selected((string) old('requires_minor_guardian', (int) $association->requires_minor_guardian) === '0')>Não exigir responsável</option></select></div><div class="field field-wide"><label for="notes">Observações</label><textarea id="notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $association->notes) }}</textarea></div></div></fieldset>
    <div class="form-actions"><a class="button button-secondary" href="{{ $editing ? route('associations.show', $association) : route('associations.index') }}">Cancelar</a><button class="button button-primary" type="submit">Salvar associação</button></div>
</form>
@endsection
