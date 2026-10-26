@extends('layouts.app')
@php($chargeNames = ['open' => 'Em aberto', 'overdue' => 'Vencida', 'partially_paid' => 'Parcial', 'paid' => 'Paga', 'cancelled' => 'Cancelada', 'waived' => 'Isenta'])
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Cobranças e pagamentos. Alterações só pela associação.'])
<div class="portal-list">@forelse($student->charges as $charge)<article class="card portal-row"><div><span class="status-pill {{ in_array($charge->status, ['paid', 'waived', 'cancelled'], true) ? 'status-inactive' : '' }}">{{ $chargeNames[$charge->status] ?? $charge->status }}</span><h3>{{ $charge->description }}</h3><p>Vencimento {{ $charge->due_date->format('d/m/Y') }}</p></div><div class="portal-values"><strong>R$ {{ \App\Support\Money\MoneyFormatter::brl($charge->balance_amount) }}</strong><small>Saldo</small></div>@if($charge->payments->isNotEmpty())<details><summary>Ver pagamentos</summary><ul>@foreach($charge->payments as $payment)<li>{{ $payment->payment_date->format('d/m/Y') }} — R$ {{ \App\Support\Money\MoneyFormatter::brl($payment->amount) }} — {{ $payment->status === 'valid' ? 'Confirmado' : 'Estornado' }}</li>@endforeach</ul></details>@endif</article>@empty<div class="card empty-state"><p>Nenhuma cobrança encontrada.</p></div>@endforelse</div>
@include('portal.partials.tabbar', ['current' => 'financeiro'])
</div>
@endsection
