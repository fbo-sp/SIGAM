@extends('layouts.app')
@section('title', 'Revisar importação — JUDOMA')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.imports.index', $association) }}">Importações</a><span>/</span><span>Lote #{{ $batch->id }}</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $batch->import_type === 'students' ? 'Alunos' : 'Presenças' }} · {{ $batch->dojo->name }}</span><h1>Revise antes de importar</h1><p>{{ $batch->original_name }}. O arquivo original é privado e não fica disponível para download.</p></div></div>

<div class="metric-grid">
    <div class="metric-card"><strong>{{ $batch->total_rows }}</strong><span>Total</span></div>
    <div class="metric-card"><strong>{{ $batch->ready_rows }}</strong><span>Prontas</span></div>
    <div class="metric-card"><strong>{{ $batch->review_rows }}</strong><span>Revisar</span></div>
    <div class="metric-card"><strong>{{ $batch->invalid_rows + $batch->failed_rows }}</strong><span>Com problema</span></div>
    <div class="metric-card"><strong>{{ $batch->processed_rows }}</strong><span>Processadas</span></div>
</div>

@if($batch->status === 'expired')<div class="alert alert-warning">Este lote expirou após o prazo de retenção. Corrija a planilha original e faça um novo envio.</div>@endif
@if(!in_array($batch->status, ['completed', 'expired']) && ($batch->ready_rows + $batch->review_rows) > 0)
<form class="card" method="post" action="{{ route('associations.imports.process', [$association, $batch]) }}">
    @csrf
    @if($batch->review_rows > 0 && !$batch->confirmed_at)<label class="check-row"><input type="checkbox" name="confirm_reviews" value="1" required><span>Revisei as {{ $batch->review_rows }} linha(s) sinalizada(s) e autorizo o reaproveitamento ou atualização indicado.</span></label>@endif
    <p>São processadas até 100 linhas por vez. Se houver mais, o botão permitirá continuar sem repetir o que já foi concluído.</p>
    <button class="button button-primary" type="submit">{{ $batch->status === 'processing' ? 'Continuar importação' : 'Confirmar e importar' }}</button>
</form>
@endif

@if(($batch->invalid_rows + $batch->failed_rows) > 0)<p><a class="button button-secondary" href="{{ route('associations.imports.errors', [$association, $batch]) }}">Baixar lista de erros</a></p>@endif

<div class="card table-wrap"><table><thead><tr><th>Linha</th><th>Situação</th><th>Mensagem</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row->row_number }}</td><td><span class="status-pill {{ in_array($row->status, ['invalid', 'failed']) ? 'status-inactive' : '' }}">{{ ['ready' => 'Pronta', 'review' => 'Revisar', 'invalid' => 'Inválida', 'processed' => 'Processada', 'already_processed' => 'Já processada', 'failed' => 'Falhou'][$row->status] ?? $row->status }}</span></td><td>{{ $row->message }}</td></tr>@endforeach
</tbody></table></div>
{{ $rows->links() }}
@endsection
