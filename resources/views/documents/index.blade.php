@extends('layouts.app')
@section('title', 'Documentos — JUDOMA')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><span>Documentos</span></nav>
<div class="page-heading"><div><span class="eyebrow">Arquivos privados</span><h1>Documentos</h1><p>Consulte validades e envie arquivos PDF, JPG ou PNG de até 10 MB.</p></div></div>

<nav class="filter-pills" aria-label="Filtrar documentos"><a href="{{ route('associations.documents.index', $association) }}">Todos</a><a href="{{ route('associations.documents.index', [$association, 'status' => 'expiring']) }}">Vencendo em 30 dias</a><a href="{{ route('associations.documents.index', [$association, 'status' => 'expired']) }}">Vencidos</a></nav>

@if(auth()->user()->hasPermission('document.manage', $association->id))
<details class="card disclosure" @if($errors->any()) open @endif><summary>Enviar novo documento</summary>
<form class="form-grid" method="post" enctype="multipart/form-data" action="{{ route('associations.documents.store', $association) }}">@csrf
<div class="field field-wide"><label for="file">Arquivo</label><input id="file" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required><small>O arquivo fica protegido e não recebe link público.</small></div>
<div class="field"><label for="document_type_id">Tipo</label><select id="document_type_id" name="document_type_id" required><option value="">Selecione</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected(old('document_type_id') == $type->id)>{{ $type->name }}{{ $type->requires_expiration ? ' · exige validade' : '' }}</option>@endforeach</select></div>
<div class="field"><label for="student_id">Aluno (opcional)</label><select id="student_id" name="student_id"><option value="">Nenhum</option>@foreach($students as $student)<option value="{{ $student->id }}" @selected(old('student_id') == $student->id)>{{ $student->person->full_name }} · {{ $student->registration_number }}</option>@endforeach</select></div>
<div class="field field-wide"><label for="person_id">Pessoa (use quando não for documento de aluno)</label><select id="person_id" name="person_id"><option value="">Selecione</option>@foreach($people as $person)<option value="{{ $person->id }}" @selected(old('person_id') == $person->id)>{{ $person->full_name }}</option>@endforeach</select></div>
<div class="field"><label for="issue_date">Data de emissão</label><input id="issue_date" type="date" name="issue_date" value="{{ old('issue_date') }}"></div><div class="field"><label for="expiration_date">Validade</label><input id="expiration_date" type="date" name="expiration_date" value="{{ old('expiration_date') }}"></div>
<div class="field field-wide"><label for="notes">Observações</label><textarea id="notes" name="notes" rows="3">{{ old('notes') }}</textarea></div><div class="form-actions field-wide"><button class="button button-primary">Enviar documento</button></div>
</form></details>
<details class="card disclosure"><summary>Criar tipo de documento</summary><form class="form-grid" method="post" action="{{ route('associations.document-types.store', $association) }}">@csrf<div class="field"><label for="name">Nome do tipo</label><input id="name" name="name" maxlength="120" required></div><label class="check-row"><input type="checkbox" name="requires_expiration" value="1">Validade obrigatória</label><div class="field field-wide"><label for="description">Orientação para o usuário</label><textarea id="description" name="description"></textarea></div><div class="form-actions field-wide"><button class="button button-secondary">Criar tipo</button></div></form></details>
@endif

<section class="section-block"><div class="section-heading"><h2>Arquivos cadastrados</h2><p>{{ $documents->total() }} resultado(s). A cor sempre acompanha um texto de estado.</p></div>
@forelse($documents as $document)
@php($state = $document->expiryState())
<article class="card document-row"><div><span class="status-pill {{ in_array($state, ['expired','expiring']) ? 'status-inactive' : '' }}">{{ ['expired'=>'Vencido','expiring'=>'Vence em breve','valid'=>'Válido','no_expiration'=>'Sem validade'][$state] }}</span><h3>{{ $document->type->name }}</h3><p><strong>{{ $document->person->full_name }}</strong> · {{ $document->original_name }}</p><small>{{ number_format($document->file_size / 1024, 0, ',', '.') }} KB @if($document->expiration_date) · validade {{ $document->expiration_date->format('d/m/Y') }} @endif</small></div><div class="row-actions">
@if(auth()->user()->hasPermission('document.download', $association->id))<a class="button button-secondary" href="{{ route('associations.documents.download', [$association, $document]) }}">Baixar</a>@endif
@if(auth()->user()->hasPermission('document.manage', $association->id))<form method="post" action="{{ route('associations.documents.destroy', [$association, $document]) }}" onsubmit="return confirm('Remover este documento do cadastro?')">@csrf @method('DELETE')<button class="button button-danger">Remover</button></form>@endif
</div></article>
@empty<div class="empty-state"><h2>Nenhum documento encontrado</h2><p>Envie o primeiro arquivo ou altere o filtro.</p></div>@endforelse
{{ $documents->links() }}</section>
@endsection
