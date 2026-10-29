@extends('layouts.app')
@section('title', 'Revisar importação — JUDOMA')
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.imports.index', $association) }}">Importações</a><span>/</span><span>Lote #{{ $batch->id }}</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $batch->import_type === 'students' ? 'Alunos' : 'Presenças' }} · {{ $batch->dojo->name }}</span><h1>Revise antes de importar</h1><p>{{ $batch->original_name }}. O arquivo original é privado e não fica disponível para download.</p></div></div>

<div class="metric-grid">
    <div class="metric-card"><strong>{{ $batch->total_rows }}</strong><span>Total</span></div>
    <div class="metric-card"><strong>{{ $batch->ready_rows }}</strong><span>Prontas</span></div>
    <div class="metric-card"><strong>{{ $batch->review_rows }}</strong><span>Revisar</span></div>
    @if(($counts['duplicate'] ?? 0) > 0)<div class="metric-card metric-duplicate"><strong>{{ $counts['duplicate'] }}</strong><span>Já cadastrados</span></div>@endif
    <div class="metric-card"><strong>{{ $batch->invalid_rows + $batch->failed_rows }}</strong><span>Com problema</span></div>
    <div class="metric-card"><strong>{{ $batch->processed_rows }}</strong><span>Processadas</span></div>
    @if(($counts['ignored'] ?? 0) > 0)<div class="metric-card"><strong>{{ $counts['ignored'] }}</strong><span>Ignoradas</span></div>@endif
</div>

@if($batch->status === 'expired')<div class="alert alert-warning">Este lote expirou após o prazo de retenção. Corrija a planilha original e faça um novo envio.</div>@endif
@if(!in_array($batch->status, ['completed', 'expired']) && ($batch->ready_rows + $batch->review_rows + ($counts['duplicate'] ?? 0)) > 0)
<form method="post" action="{{ route('associations.imports.process', [$association, $batch]) }}">
    @csrf
    @if($duplicates->isNotEmpty())
    <section class="card dup-bulk"><strong>{{ $duplicates->count() }} aluno(s) já existem no JUDOMA. O que fazer com eles?</strong><button class="button button-secondary" type="button" data-check-all="ignore">Ignorar todas</button><button class="button button-secondary" type="button" data-check-all="update">Atualizar todas</button></section>
    <div class="dup-list">
        @foreach($duplicates as $row)
        @php($dup = $row->normalized_payload['duplicate'])
        @php($choice = old('acoes.'.$row->id, $dup['action'] ?? 'ignore'))
        <article class="card dup-card">
            <div class="dup-head"><span><strong>Linha {{ $row->row_number }} · {{ $row->normalized_payload['person']['full_name'] }}</strong><br><small>{{ $dup['by'] === 'name' ? 'Parece ser' : 'Já cadastrado como' }} {{ $dup['summary'] }}</small></span><span class="dup-match {{ $dup['by'] === 'name' ? 'weak' : '' }}">{{ \App\Services\Import\StudentDuplicateFinder::MATCH_LABELS[$dup['by']] }}</span></div>
            <ul class="dup-diff">
                @forelse($dup['changes'] as [$label, $old, $new])<li><b>{{ $label }}</b><del>{{ $old ?? 'vazio' }}</del><span aria-hidden="true">→</span><ins>{{ $new }}</ins></li>@empty @if(!$dup['enroll'])<li><b>Nenhuma diferença</b></li>@endif @endforelse
                @if($dup['enroll'])<li><b>Turma</b><ins>matricular em {{ $dup['enroll'] }}</ins></li>@endif
                @if($dup['kept_birth'] ?? null)<li><b>Nascimento</b><span>{{ $dup['kept_birth'] }} no JUDOMA, vazio na planilha (mantém a data)</span></li>@endif
            </ul>
            <div class="dup-choices">
                <label><input type="radio" name="acoes[{{ $row->id }}]" value="ignore" @checked($choice === 'ignore')> Ignorar linha</label>
                <label><input type="radio" name="acoes[{{ $row->id }}]" value="update" @checked($choice === 'update')> Atualizar com a planilha</label>
                @if($dup['by'] === 'name')<label><input type="radio" name="acoes[{{ $row->id }}]" value="new" @checked($choice === 'new')> Cadastrar como novo aluno</label>@endif
            </div>
        </article>
        @endforeach
    </div>
    @endif
    <div class="card dup-confirm">
        @if($batch->review_rows > 0 && !$batch->confirmed_at)<label class="check-row"><input type="checkbox" name="confirm_reviews" value="1" required><span>Revisei as {{ $batch->review_rows }} linha(s) sinalizada(s) e autorizo o reaproveitamento ou atualização indicado.</span></label>@endif
        <p>@if($duplicates->isNotEmpty())Linhas novas entram como sempre. As já cadastradas seguem a escolha de cada uma. @endif São processadas até 100 linhas por vez. Se houver mais, o botão permitirá continuar sem repetir o que já foi concluído.</p>
        <button class="button button-primary" type="submit">{{ $batch->status === 'processing' ? 'Continuar importação' : 'Confirmar e importar' }}</button>
    </div>
</form>
@endif

@if(($batch->invalid_rows + $batch->failed_rows) > 0)<p><a class="button button-secondary" href="{{ route('associations.imports.errors', [$association, $batch]) }}">Baixar lista de erros</a></p>@endif

<div class="card table-wrap"><table><thead><tr><th>Linha</th><th>Situação</th><th>Mensagem</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row->row_number }}</td><td><span class="status-pill {{ in_array($row->status, ['invalid', 'failed']) ? 'status-inactive' : '' }}">{{ ['ready' => 'Pronta', 'review' => 'Revisar', 'invalid' => 'Inválida', 'processed' => 'Processada', 'already_processed' => 'Já processada', 'failed' => 'Falhou', 'duplicate' => 'Já cadastrado', 'ignored' => 'Ignorada'][$row->status] ?? $row->status }}</span></td><td>{{ $row->message }}</td></tr>@endforeach
</tbody></table></div>
{{ $rows->links() }}
@endsection
