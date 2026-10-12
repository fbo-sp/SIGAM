@extends('layouts.app')
@section('title', 'Cobrança — JUDOMA')
@section('content')
@php($labels = ['open'=>'Em aberto','overdue'=>'Vencida','waived'=>'Isenta','partially_paid'=>'Parcialmente paga','paid'=>'Paga','cancelled'=>'Cancelada'])
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><a href="{{ route('associations.charges.index', $association) }}">Cobranças</a><span>/</span><span>Detalhes</span></nav>
<div class="page-heading"><div><span class="eyebrow">Cobrança #{{ $charge->id }}</span><h1>{{ $charge->student?->person?->full_name ?? 'Pessoa não vinculada' }}</h1><p>{{ $charge->description }}</p></div><button class="button button-secondary" type="button" data-action="print">Imprimir registro</button></div>

<section class="quick-grid" aria-label="Resumo da cobrança">
    <article class="card quick-card"><small>Situação</small><h2>{{ $labels[$charge->status] ?? $charge->status }}</h2><p>Vencimento {{ $charge->due_date->format('d/m/Y') }}</p></article>
    <article class="card quick-card"><small>Valor final</small><strong class="metric">R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->final_amount) }}</strong><p>Desconto R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->discount_amount) }}</p></article>
    <article class="card quick-card"><small>Saldo pendente</small><strong class="metric">R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->balance_amount) }}</strong><p>Pago R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->paid_amount) }}</p></article>
</section>

@if(auth()->user()->hasPermission('payment.manage', $association->id) && in_array($charge->status, ['open','overdue','partially_paid'], true))
<section class="card"><div class="section-heading"><h2>Registrar pagamento</h2><p>O saldo é atualizado automaticamente. Não é permitido pagar além do valor pendente.</p></div>
<form method="post" action="{{ route('associations.payments.store', [$association, $charge]) }}">@csrf
    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    <div class="form-grid"><div class="field"><label for="payment_date">Data do pagamento</label><input id="payment_date" type="date" name="payment_date" value="{{ old('payment_date', today()->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}" required></div>
    <div class="field"><label for="amount">Valor</label><input id="amount" name="amount" inputmode="decimal" value="{{ old('amount', $charge->balance_amount) }}" required><small>Saldo atual: R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->balance_amount) }}</small></div>
    <div class="field"><label for="payment_method">Forma de pagamento</label><select id="payment_method" name="payment_method" required>@foreach(['pix'=>'PIX','cash'=>'Dinheiro','card'=>'Cartão','transfer'=>'Transferência','boleto'=>'Boleto','other'=>'Outro'] as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method')===$value)>{{ $label }}</option>@endforeach</select></div>
    <div class="field"><label for="reference">Referência (opcional)</label><input id="reference" name="reference" value="{{ old('reference') }}" maxlength="255" placeholder="Ex.: ID da transação"></div></div>
    <div class="field"><label for="notes">Observações (opcional)</label><textarea id="notes" name="notes" rows="3">{{ old('notes') }}</textarea></div>
    <div class="form-actions"><button class="button button-primary">Confirmar pagamento</button></div>
</form></section>
@endif

<section class="section-block"><div class="section-heading"><h2>Histórico de pagamentos</h2><p>Pagamentos estornados permanecem visíveis para auditoria.</p></div>
@forelse($charge->payments->sortByDesc('posted_at') as $payment)<article class="card charge-row"><div><span class="status-pill {{ $payment->status === 'reversed' ? 'status-inactive' : '' }}">{{ $payment->status === 'valid' ? 'Válido' : 'Estornado' }}</span><h3>R$ {{ \App\Support\Money\MoneyFormatter::brl($payment->amount) }}</h3><p>{{ ['pix'=>'PIX','cash'=>'Dinheiro','card'=>'Cartão','transfer'=>'Transferência','boleto'=>'Boleto','other'=>'Outro'][$payment->payment_method] ?? $payment->payment_method }} · pago em {{ $payment->payment_date->format('d/m/Y') }}</p><small>Registrado por {{ $payment->creator->name }} em {{ $payment->posted_at->format('d/m/Y H:i') }}@if($payment->reference) · referência {{ $payment->reference }}@endif</small>@if($payment->status === 'reversed')<p><strong>Motivo do estorno:</strong> {{ $payment->reversal_reason }}</p><small>Estornado por {{ $payment->reverser?->name }} em {{ $payment->reversed_at?->format('d/m/Y H:i') }}</small>@endif</div>
@if($payment->status === 'valid' && auth()->user()->hasPermission('payment.reverse', $association->id))<details><summary>Estornar pagamento</summary><form method="post" action="{{ route('associations.payments.reverse', [$association, $payment]) }}">@csrf<label for="reversal_reason_{{ $payment->id }}">Motivo detalhado</label><textarea id="reversal_reason_{{ $payment->id }}" name="reversal_reason" rows="3" minlength="10" required></textarea><button class="button button-danger">Confirmar estorno</button></form></details>@endif</article>@empty<div class="empty-state"><h2>Nenhum pagamento</h2><p>Esta cobrança ainda não recebeu pagamentos.</p></div>@endforelse
</section>
<details class="card disclosure"><summary>Como o valor foi calculado</summary><ol>@foreach($charge->calculation_snapshot['explanation'] ?? [] as $line)<li>{{ $line }}</li>@endforeach</ol></details>
<p><small>Este documento é um registro operacional interno do JUDOMA e não substitui recibo fiscal ou documento exigido por lei.</small></p>
@endsection
