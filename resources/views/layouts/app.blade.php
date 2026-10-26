<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'JUDOMA')</title>
    <link rel="stylesheet" href="{{ asset('css/sigam.css') }}">
    <link rel="stylesheet" href="{{ asset('css/direct-registration.css') }}">
    <link rel="stylesheet" href="{{ asset('css/modules.css') }}">
</head>
@php($teacherNav = auth()->check() && request()->routeIs('dashboard', 'teacher.*', 'operation.*', 'notifications.*') && app(\App\Services\Classes\TeacherWorkspaceService::class)->usesNavigation(auth()->user()))
<body @class(['has-teacher-nav' => $teacherNav])>
    <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>
    @auth
        <header class="topbar">
            <a class="brand" href="{{ route('dashboard') }}" aria-label="JUDOMA — página inicial">JUDOMA</a>
            <nav class="main-nav" aria-label="Navegação principal">
                @php($navAssociation = auth()->user()->association_id)
                @php($portalOnly = auth()->user()->hasPermission('portal.access', $navAssociation) && ! collect(['dashboard.association', 'dashboard.dojo', 'dashboard.teacher'])->contains(fn ($code) => auth()->user()->hasPermission($code, $navAssociation)))
                <a href="{{ route('dashboard') }}">Início</a>
                @if(! $portalOnly || \App\Models\DojoRoleAssignment::query()->where('user_id', auth()->id())->whereNull('revoked_at')->exists())<a href="{{ route('operation.index') }}">Meus dojos</a>@endif
                @if(! $portalOnly && auth()->user()->hasPermission('portal.access', $navAssociation))
                    <a href="{{ route('portal.index') }}">Meu portal</a>
                @endif
                @if(auth()->user()->hasPermission('association.view', auth()->user()->association_id))
                    <a href="{{ route('associations.index') }}">Associações</a>
                @endif
                @php($unreadNotifications = auth()->user()->notifications()->where('association_id', auth()->user()->association_id)->where('channel', 'internal')->whereNull('read_at')->count())
                <a href="{{ route('notifications.index') }}">Notificações{{ $unreadNotifications ? ' ('.$unreadNotifications.')' : '' }}</a>
            </nav>
            <div class="account">
                <span>
                    <strong>{{ auth()->user()->name }}</strong>
                    @if($currentAssociation ?? null)
                        <small>{{ $currentAssociation->trade_name }}</small>
                    @endif
                </span>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="button button-secondary" type="submit">Sair</button>
                </form>
            </div>
        </header>
    @endauth

    <main id="conteudo" class="page-shell">
        @if($teacherNav)@include('teacher.partials.tabbar')@endif
        @if(session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                <strong>Revise os campos indicados.</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>
    <script src="{{ asset('js/common.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
