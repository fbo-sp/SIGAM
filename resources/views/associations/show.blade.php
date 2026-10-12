@extends('layouts.app')

@section('title', $association->trade_name.' — JUDOMA')

@section('content')
<nav class="breadcrumbs" aria-label="Navegação estrutural"><a href="{{ route('associations.index') }}">Associações</a><span>/</span><span>{{ $association->trade_name }}</span></nav>
<div class="page-heading">
    <div><span class="eyebrow">Associação</span><h1>{{ $association->trade_name }}</h1><p>{{ $association->legal_name }}</p></div>
    @if(auth()->user()->hasPermission('association.manage', $association->id))
        <a class="button button-secondary" href="{{ route('associations.edit', $association) }}">Editar dados</a>
    @endif
</div>

@if($association->status === 'inactive')<div class="alert alert-warning">Esta associação está inativa. Novos vínculos operacionais devem permanecer bloqueados.</div>@endif

<section class="quick-grid" aria-label="Cadastros da associação">
    <article class="card quick-card"><span class="metric">{{ $dojoCount }}</span><h2>Dojos</h2><p>Unidades e locais vinculados.</p>@if(auth()->user()->hasPermission('dojo.view', $association->id))<a class="card-link" href="{{ route('associations.dojos.index', $association) }}">Ver dojos</a>@endif</article>
    <article class="card quick-card"><span class="metric">{{ $peopleCount }}</span><h2>Pessoas</h2><p>Cadastro central de contatos e dados pessoais.</p>@if(auth()->user()->hasPermission('person.view', $association->id))<a class="card-link" href="{{ route('associations.people.index', $association) }}">Ver pessoas</a>@endif</article>
    <article class="card quick-card is-muted"><span class="status-pill">Próxima etapa</span><h2>Perfis esportivos</h2><p>Alunos, responsáveis e professores serão vinculados às pessoas.</p></article>
</section>
<section class="section-block"><div class="section-heading"><h2>Cadastros esportivos</h2><p>Configure as modalidades antes de criar alunos e professores.</p></div><div class="action-list">
    @if(auth()->user()->hasPermission('user.manage', $association->id))<a href="{{ route('associations.users.index', $association) }}"><strong>Usuários</strong><span>Contas, perfis, convites e portal</span></a>@endif
    @if(auth()->user()->hasPermission('role.manage', $association->id))<a href="{{ route('dojo-access.index', $association) }}"><strong>Acessos aos dojos</strong><span>Conceda acesso por função, unidade e período</span></a>@endif
    @if(auth()->user()->hasPermission('martial_art.view', $association->id))<a href="{{ route('associations.catalog.index', $association) }}"><strong>Modalidades e graduações</strong><span>Faixas, níveis e regras configuráveis</span></a>@endif
    @if(auth()->user()->hasPermission('student.view', $association->id))<a href="{{ route('associations.students.index', $association) }}"><strong>Alunos</strong><span>Pré-cadastro, responsáveis e ativação</span></a>@endif
    @if(auth()->user()->hasPermission('teacher.view', $association->id))<a href="{{ route('associations.teachers.index', $association) }}"><strong>Professores</strong><span>Habilitações por modalidade</span></a>@endif
    @if(auth()->user()->hasPermission('class.view', $association->id))<a href="{{ route('associations.classes.index', $association) }}"><strong>Turmas e horários</strong><span>Agenda vigente e matrículas</span></a>@endif
    @if(auth()->user()->hasPermission('session.view', $association->id))<a href="{{ route('associations.attendance.index', $association) }}"><strong>Aulas e chamada</strong><span>Agenda diária e controle de presença</span></a>@endif
    @if(auth()->user()->hasPermission('import.manage', $association->id))<a href="{{ route('associations.imports.index', $association) }}"><strong>Importar alunos e presenças</strong><span>Envio por planilha com prévia, revisão e relatório</span></a>@endif
    @if(auth()->user()->hasPermission('document.view', $association->id))<a href="{{ route('associations.documents.index', $association) }}"><strong>Documentos</strong><span>Arquivos privados, validades e vencimentos</span></a>@endif
    @if(auth()->user()->hasPermission('finance.view', $association->id))<a href="{{ route('associations.finance.index', $association) }}"><strong>Planos e benefícios</strong><span>Mensalidades, descontos, gratuidades e simulação</span></a>@endif
    @if(auth()->user()->hasPermission('charge.view', $association->id))<a href="{{ route('associations.charges.index', $association) }}"><strong>Cobranças</strong><span>Em aberto, vencidas e extrato dos alunos</span></a>@endif
    @if(auth()->user()->hasPermission('finance.report', $association->id))<a href="{{ route('associations.finance.reports.index', $association) }}"><strong>Relatórios financeiros</strong><span>Previsto, recebido e valores pendentes</span></a>@endif
    @if(auth()->user()->hasPermission('federation.view', $association->id))<a href="{{ route('associations.federation.index', $association) }}"><strong>Federações e filiações</strong><span>Histórico, documentos e vencimentos federativos</span></a>@endif
    @if(auth()->user()->hasPermission('championship.view', $association->id))<a href="{{ route('associations.championships.index', $association) }}"><strong>Campeonatos</strong><span>Inscrições, cobranças, resultados e medalhas</span></a>@endif
</div></section>
@endsection
