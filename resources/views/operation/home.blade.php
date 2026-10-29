@extends('layouts.app')
@section('title', $dojo->name.' — Operação')
@section('content')
@include('partials.back-to-dashboard')
<nav class="breadcrumbs"><a href="{{ route('operation.index') }}">Meus dojos</a><span>/</span><span>{{ $dojo->name }}</span></nav>
<div class="page-heading"><div><span class="eyebrow">Operação do dojo</span><h1>{{ $dojo->name }}</h1><p>Você verá somente as funções autorizadas para esta unidade.</p></div></div>
<section class="quick-grid" aria-label="Funções do dojo">
    @if($capabilities['student.view'])<article class="card quick-card"><h2>Alunos</h2><p>Vínculos regulares ativos nesta unidade.</p><a class="card-link" href="{{ route('operation.students', $dojo) }}">Ver alunos</a></article>@endif
    @if($capabilities['student.view'])<article class="card quick-card"><h2>Aniversariantes</h2><p>Alunos e professores do dojo que fazem aniversário no mês.</p><a class="card-link" href="{{ route('operation.birthdays', $dojo) }}">Ver aniversariantes</a></article>@endif
    @if($capabilities['class.view'])<article class="card quick-card"><h2>Turmas</h2><p>Turmas autorizadas para sua função.</p><a class="card-link" href="{{ route('operation.classes', $dojo) }}">Ver turmas</a></article>@endif
    @if($capabilities['session.view'])<article class="card quick-card"><h2>Aulas e chamada</h2><p>Professores veem apenas as aulas em que atuam.</p><a class="card-link" href="{{ route('operation.sessions', $dojo) }}">Ver aulas</a></article>@endif
    @if($capabilities['progression.report'])<article class="card quick-card"><h2>Acompanhamento técnico</h2><p>O que as turmas precisam e o que foi trabalhado nas aulas.</p><a class="card-link" href="{{ route('operation.progression.panel', $dojo) }}">Abrir painel</a></article>@endif
    @if($capabilities['announcement.send'])<article class="card quick-card"><h2>Avisos</h2><p>{{ $capabilities['class.manage'] ? 'Envie para todo o dojo, turmas ou pessoas.' : 'Envie para as suas turmas e alunos.' }}</p><a class="card-link" href="{{ route('operation.announcements.index', $dojo) }}">Abrir avisos</a></article>@endif
    @if($capabilities['teacher.view'])<article class="card quick-card"><h2>Professores</h2><p>Equipe escolhida entre os professores da associação.</p><a class="card-link" href="{{ route('operation.teachers', $dojo) }}">Ver equipe</a></article>@endif
    @if($capabilities['charge.view'])<article class="card quick-card"><h2>Financeiro do dojo</h2><p>Planos, gratuidades, cobranças e pagamentos somente desta unidade.</p><a class="card-link" href="{{ route('operation.finance.index', $dojo) }}">Abrir financeiro</a></article>@endif
    @if($capabilities['championship.view'])<article class="card quick-card"><h2>Campeonatos</h2><p>Eventos da associação e inscrições dos alunos desta unidade.</p><a class="card-link" href="{{ route('operation.championships.index', $dojo) }}">Ver campeonatos</a></article>@endif
    @if($capabilities['report.export'])<article class="card quick-card"><h2>Relatórios</h2><p>{{ app(\App\Services\Access\OperationalScope::class)->canDojo(auth()->user(), $dojo, 'report.export') ? 'Lista de alunos do dojo em Excel.' : 'Lista de alunos das suas turmas em Excel.' }}</p><a class="card-link" href="{{ app(\App\Services\Access\OperationalScope::class)->canDojo(auth()->user(), $dojo, 'report.export') ? route('operation.reports.index', $dojo) : route('teacher.reports.index') }}">Abrir relatórios</a></article>@endif
    @if($capabilities['import.manage'])<article class="card quick-card"><h2>Importar planilha</h2><p>Cadastre alunos ou presenças com prévia e revisão.</p><a class="card-link" href="{{ route('associations.imports.index', $dojo->association_id) }}">Abrir importações</a></article>@endif
</section>
@endsection
