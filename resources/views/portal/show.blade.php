@extends('layouts.app')

@section('title', 'Portal de '.$student->person->full_name.' — JUDOMA')

@php
    $weekdayNames = [0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'];
    $chargeNames = ['open' => 'Em aberto', 'overdue' => 'Vencida', 'partially_paid' => 'Parcial', 'paid' => 'Paga', 'cancelled' => 'Cancelada', 'waived' => 'Isenta'];
    $registrationNames = ['pending' => 'Em análise', 'approved' => 'Aprovada', 'rejected' => 'Recusada', 'cancelled' => 'Cancelada'];
@endphp

@section('content')
<nav class="breadcrumbs" aria-label="Caminho"><a href="{{ route('portal.index') }}">Meu portal</a><span>/</span><span>{{ $student->person->social_name ?: $student->person->full_name }}</span></nav>

<section class="page-heading portal-heading">
    <div>
        <span class="eyebrow">{{ $student->person_id === auth()->user()->person_id ? 'Meus dados' : 'Dependente autorizado' }}</span>
        <h1>{{ $student->person->social_name ?: $student->person->full_name }}</h1>
        <p>Matrícula {{ $student->registration_number }} · {{ $student->primaryDojo?->name ?: 'Dojo não informado' }}</p>
    </div>
</section>

@if($students->count() > 1)
<section class="dependent-switcher" aria-label="Trocar aluno">
    <strong>Você está consultando:</strong>
    <div>
        @foreach($students as $option)
            <a href="{{ route('portal.students.show', $option) }}" @class(['is-current' => $option->is($student)]) aria-current="{{ $option->is($student) ? 'page' : 'false' }}">{{ $option->person->social_name ?: $option->person->full_name }}</a>
        @endforeach
    </div>
</section>
@endif

<nav class="portal-nav" aria-label="Seções do portal">
    <a href="#resumo">Resumo</a><a href="#turmas">Turmas</a><a href="#frequencia">Frequência</a><a href="#financeiro">Financeiro</a><a href="#documentos">Documentos</a><a href="#historico">Histórico</a><a href="#campeonatos">Campeonatos</a>
</nav>

<section id="resumo" class="section-block">
    <div class="section-heading"><h2>Resumo e contatos</h2><p>Apenas os campos abaixo podem ser alterados pelo portal.</p></div>
    <div class="portal-metrics">
        <article class="card"><span>Turmas ativas</span><strong>{{ $student->enrollments->where('status', 'active')->count() }}</strong></article>
        <article class="card"><span>Presenças recentes</span><strong>{{ $attendanceSummary['present'] }}/{{ $attendanceSummary['total'] }}</strong></article>
        <article class="card"><span>Cobranças em aberto</span><strong>{{ $student->charges->whereIn('status', \App\Models\Charge::OUTSTANDING_STATUSES)->count() }}</strong></article>
        <article class="card"><span>Documentos</span><strong>{{ $student->documents->count() }}</strong></article>
    </div>
    @if($canEditContact)
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
</section>

<section id="turmas" class="section-block"><div class="section-heading"><h2>Turmas e horários</h2><p>Vínculos atuais e anteriores.</p></div>
    <div class="portal-list">@forelse($student->enrollments as $enrollment)<article class="card portal-row"><div><span class="status-pill">{{ $enrollment->status === 'active' ? 'Ativa' : 'Encerrada' }}</span><h3>{{ $enrollment->classGroup->name }}</h3><p>{{ $enrollment->classGroup->martialArt->name }} · {{ $enrollment->classGroup->dojo->name }}</p></div><ul>@forelse($enrollment->classGroup->schedules->where('status', 'active') as $schedule)<li>{{ $weekdayNames[$schedule->weekday] ?? 'Dia '.$schedule->weekday }}, {{ substr($schedule->start_time, 0, 5) }}–{{ substr($schedule->end_time, 0, 5) }}</li>@empty<li>Horário não informado.</li>@endforelse</ul></article>@empty<div class="card empty-state"><p>Nenhuma turma encontrada.</p></div>@endforelse</div>
</section>

<section id="frequencia" class="section-block"><div class="section-heading"><h2>Frequência</h2><p>Últimos {{ $attendanceSummary['total'] }} registros de chamada.</p></div>
    <div class="portal-metrics"><article class="card"><span>Presente</span><strong>{{ $attendanceSummary['present'] }}</strong></article><article class="card"><span>Ausente</span><strong>{{ $attendanceSummary['absent'] }}</strong></article><article class="card"><span>Justificada</span><strong>{{ $attendanceSummary['justified'] }}</strong></article></div>
    <div class="portal-list">@foreach($attendance as $record)<article class="card portal-row"><div><span class="status-pill">{{ ['present' => 'Presente', 'absent' => 'Ausente', 'excused_absence' => 'Falta justificada', 'makeup' => 'Reposição', 'trial' => 'Experimental'][$record->status] ?? $record->status }}</span><h3>{{ $record->session->classGroup->name }}</h3><p>{{ $record->session->session_date->format('d/m/Y') }} · {{ $record->participation_type === 'visitor' ? 'Visita' : 'Matrícula regular' }}</p></div></article>@endforeach</div>
</section>

<section id="financeiro" class="section-block"><div class="section-heading"><h2>Financeiro</h2><p>Cobranças e pagamentos, sem permitir alterações pelo portal.</p></div>
    <div class="portal-list">@forelse($student->charges as $charge)<article class="card portal-row"><div><span class="status-pill">{{ $chargeNames[$charge->status] ?? $charge->status }}</span><h3>{{ $charge->description }}</h3><p>Vencimento {{ $charge->due_date->format('d/m/Y') }}</p></div><div class="portal-values"><strong>R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->balance_amount) }}</strong><small>Saldo</small></div>@if($charge->payments->isNotEmpty())<details><summary>Ver pagamentos</summary><ul>@foreach($charge->payments as $payment)<li>{{ $payment->payment_date->format('d/m/Y') }} — R$ {{ \App\Support\Money\MoneyFormatter::brl($payment->amount) }} — {{ $payment->status === 'valid' ? 'Confirmado' : 'Estornado' }}</li>@endforeach</ul></details>@endif</article>@empty<div class="card empty-state"><p>Nenhuma cobrança encontrada.</p></div>@endforelse</div>
</section>

<section id="documentos" class="section-block"><div class="section-heading"><h2>Documentos</h2><p>Os arquivos continuam privados e cada download é auditado.</p></div>
    <div class="portal-list">@forelse($student->documents as $document)<article class="card portal-row"><div><span class="status-pill">{{ $document->type->name }}</span><h3>{{ $document->original_name }}</h3><p>{{ $document->expiration_date ? 'Validade '.$document->expiration_date->format('d/m/Y') : 'Sem vencimento informado' }}</p></div><a class="button button-secondary" href="{{ route('portal.students.documents.download', [$student, $document]) }}">Baixar</a></article>@empty<div class="card empty-state"><p>Nenhum documento disponível.</p></div>@endforelse</div>
</section>

<section id="historico" class="section-block"><div class="section-heading"><h2>Histórico esportivo e federações</h2><p>Graduações, filiações e resultados consolidados.</p></div>
    <div class="portal-history-grid">@foreach($student->martialArts as $practice)<article class="card portal-history"><h3>{{ $practice->martialArt->name }}</h3><p>Graduação atual: <strong>{{ $practice->currentRank?->display_name ?: 'Não informada' }}</strong></p><ul>@foreach($practice->rankHistory->sortByDesc('achieved_at') as $rank)<li>{{ $rank->achieved_at->format('d/m/Y') }} — {{ $rank->rank->display_name }}</li>@endforeach</ul></article>@endforeach
    @foreach($student->federationMemberships as $membership)<article class="card portal-history"><h3>{{ $membership->federation->name }}</h3><p>{{ $membership->martialArt->name }} · registro {{ $membership->registration_number }}</p><p>Situação: {{ $membership->status === 'active' ? 'Ativa' : 'Inativa' }} · {{ $membership->points }} ponto(s)</p></article>@endforeach</div>
</section>

<section id="campeonatos" class="section-block"><div class="section-heading"><h2>Campeonatos</h2><p>Acompanhe inscrições e participe quando a janela estiver aberta.</p></div>
    <div class="portal-list">@foreach($student->championshipRegistrations as $registration)<article class="card portal-row"><div><span class="status-pill">{{ $registrationNames[$registration->status] ?? $registration->status }}</span><h3>{{ $registration->championship->name }}</h3><p>{{ $registration->championship->start_date->format('d/m/Y') }} · {{ $registration->category }}</p></div>@if($registration->result)<strong>{{ $registration->result->placement ? $registration->result->placement.'º lugar' : 'Participação registrada' }}</strong>@endif</article>@endforeach</div>
    @foreach($championships as $championship)
        @unless($student->championshipRegistrations->contains('championship_id', $championship->id))
        <details class="card disclosure portal-registration"><summary>Inscrever em {{ $championship->name }} — {{ $championship->start_date->format('d/m/Y') }}</summary>
            <form class="form-grid" method="post" action="{{ route('portal.students.championships.register', [$student, $championship]) }}">@csrf
                <div class="field"><label>Categoria</label><input name="category" required maxlength="120" value="{{ old('category') }}"></div>
                <div class="field"><label>Peso (kg)</label><input name="weight" required inputmode="decimal" value="{{ old('weight') }}"></div>
                <div class="field"><label>Graduação</label><select name="rank_id" required><option value="">Selecione</option>@foreach($student->martialArts->where('martial_art_id', $championship->martial_art_id)->flatMap->rankHistory->unique('rank_id') as $history)<option value="{{ $history->rank_id }}">{{ $history->rank->display_name }}</option>@endforeach</select></div>
                <div class="field"><label>Filiação (opcional)</label><select name="federation_membership_id"><option value="">Sem filiação</option>@foreach($student->federationMemberships->where('martial_art_id', $championship->martial_art_id)->where('status', 'active') as $membership)<option value="{{ $membership->id }}">{{ $membership->federation->name }} — {{ $membership->registration_number }}</option>@endforeach</select></div>
                <div class="field"><label>Equipe (opcional)</label><input name="team_name" maxlength="180"></div><div class="field"><label>Observação (opcional)</label><input name="notes" maxlength="1000"></div>
                <div class="field field-wide"><button class="button button-primary" type="submit">Enviar inscrição</button></div>
            </form>
        </details>
        @endunless
    @endforeach
</section>
@endsection
