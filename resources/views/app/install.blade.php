@extends('layouts.app')
@section('title', 'Instalar o app — JUDOMA')
@section('content')
@include('partials.back-to-dashboard')
<header class="page-heading"><div><span class="eyebrow">Mais</span><h1>Instalar o app</h1><p>O JUDOMA funciona como app no celular, com ícone na tela inicial e em tela cheia. Não precisa de loja.</p></div></header>
<p class="alert alert-success" data-pwa-installed hidden>Você já está usando o JUDOMA como app.</p>
<button class="button button-primary button-block pwa-install-now" type="button" data-pwa-install-page hidden>Instalar agora</button>
<div class="pwa-steps">
    <section class="card">
        <h2>No iPhone (Safari)</h2>
        <ol>
            <li>Toque em <strong>Compartilhar</strong> (quadrado com a seta para cima), na barra de baixo.</li>
            <li>Escolha <strong>Adicionar à Tela de Início</strong>.</li>
            <li>Toque em <strong>Adicionar</strong>.</li>
        </ol>
        <p class="form-help">No iPhone a instalação é feita pelo botão Compartilhar, de preferência no Safari.</p>
    </section>
    <section class="card">
        <h2>No Android (Chrome)</h2>
        <ol>
            <li>Toque em <strong>Instalar</strong> quando o aviso aparecer, ou abra o menu <strong>⋮</strong> e escolha <strong>Instalar app</strong> (ou <strong>Adicionar à tela inicial</strong>).</li>
            <li>Confirme em <strong>Instalar</strong>.</li>
        </ol>
    </section>
    <section class="card">
        <h2>Bom saber</h2>
        <ul>
            <li>O app precisa de internet: sem conexão, ele mostra um aviso e nada do que já foi salvo se perde.</li>
            <li>As atualizações chegam sozinhas; não precisa reinstalar.</li>
            <li>Para remover, apague o ícone como qualquer outro app.</li>
        </ul>
    </section>
</div>
@endsection
