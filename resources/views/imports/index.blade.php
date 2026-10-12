@extends('layouts.app')
@section('title', 'Importações — JUDOMA')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><span>Importações</span></nav>
<div class="page-heading"><div><span class="eyebrow">Cadastro assistido</span><h1>Importar alunos e presenças</h1><p>Baixe o modelo, preencha sem mudar os títulos das colunas e envie para revisão.</p></div></div>

<div class="card">
    <h2>1. Baixe um modelo</h2>
    <div class="actions">
        <a class="button button-secondary" href="{{ route('imports.template', ['students', 'csv']) }}">Alunos em CSV</a>
        <a class="button button-secondary" href="{{ route('imports.template', ['attendance', 'csv']) }}">Presenças em CSV</a>
        <a class="button button-secondary" href="{{ route('imports.template', ['complete', 'xlsx']) }}">Modelo completo em Excel</a>
    </div>
</div>

<form class="card form-grid" method="post" enctype="multipart/form-data" action="{{ route('associations.imports.store', $association) }}">
    @csrf
    <div class="field"><label for="dojo_id">Dojo</label><select id="dojo_id" name="dojo_id" required><option value="">Selecione</option>@foreach($dojos as $dojo)<option value="{{ $dojo->id }}" @selected(old('dojo_id') == $dojo->id)>{{ $dojo->name }}</option>@endforeach</select></div>
    <div class="field"><label for="import_type">O que deseja importar?</label><select id="import_type" name="import_type" required><option value="students" @selected(old('import_type') === 'students')>Alunos</option><option value="attendance" @selected(old('import_type') === 'attendance')>Presenças</option></select></div>
    <div class="field field-wide"><label for="file">Arquivo CSV ou XLSX (até 5 MB)</label><input id="file" type="file" name="file" accept=".csv,.xlsx" required><small>Para Excel, mantenha as abas “Alunos” e “Presenças”. Máximo de 1.000 linhas por envio.</small></div>
    <div class="form-actions field-wide"><button class="button button-primary" type="submit">Analisar arquivo</button></div>
</form>

<div class="card list-card">
    <h2>Envios anteriores</h2>
    @forelse($batches as $batch)
        <a class="list-row" href="{{ route('associations.imports.show', [$association, $batch]) }}"><span><strong>{{ $batch->import_type === 'students' ? 'Alunos' : 'Presenças' }} · {{ $batch->dojo->name }}</strong><small>{{ $batch->original_name }} · {{ $batch->created_at->format('d/m/Y H:i') }}</small></span><span class="status-pill">{{ $batch->status === 'completed' ? 'Concluído' : ($batch->status === 'expired' ? 'Expirado' : 'Em revisão') }}</span></a>
    @empty
        <div class="empty-state"><h2>Nenhum arquivo enviado</h2><p>O primeiro envio aparecerá aqui.</p></div>
    @endforelse
</div>
{{ $batches->links() }}
@endsection
