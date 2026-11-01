<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'JUDOMA')</title>
    <meta name="theme-color" content="#43a9e8">
    <meta name="apple-mobile-web-app-title" content="JUDOMA">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="judoma-sw" content="{{ asset('sw.js') }}?v={{ \App\Support\Pwa\PwaVersion::current() }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
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
                @if(! $portalOnly && app(\App\Services\Portal\PortalAccessService::class)->canEnter(auth()->user()))
                    <a href="{{ route('portal.index') }}">Meu portal</a>
                @endif
                @if(auth()->user()->hasPermission('association.view', auth()->user()->association_id))
                    <a href="{{ route('associations.index') }}">Associações</a>
                @endif
                @php($unreadNotifications = auth()->user()->notifications()->where('association_id', auth()->user()->association_id)->where('channel', 'internal')->whereNull('read_at')->count())
                <a href="{{ route('notifications.index') }}">Notificações{{ $unreadNotifications ? ' ('.$unreadNotifications.')' : '' }}</a>
            </nav>
            <div class="account">
                @php($accountStudent = app(\App\Services\Portal\PortalAccessService::class)->accountStudent(auth()->user()))
                <a class="account-name" href="{{ $accountStudent ? route('portal.students.section', [$accountStudent, 'dados']) : route('account.show') }}" title="{{ $accountStudent ? 'Meus dados' : 'Minha conta' }}">
                    <span>
                        <strong>{{ auth()->user()->name }}</strong>
                        <small>{{ ($currentAssociation ?? null)?->trade_name ? $currentAssociation->trade_name.' · ' : '' }}{{ $accountStudent ? 'Meus dados' : 'Minha conta' }}</small>
                    </span>
                </a>
                <form method="post" action="{{ route('logout') }}" data-pwa-logout>
                    @csrf
                    <button class="button button-secondary" type="submit">Sair</button>
                </form>
            </div>
        </header>
    @endauth

    <main id="conteudo" class="page-shell">
        @if($teacherNav)@include('teacher.partials.tabbar')@endif
        @auth
            <aside class="pwa-banner" data-pwa-banner hidden aria-label="Instalar o app">
                <img src="{{ asset('icons/icon-192.png') }}" alt="" width="48" height="48">
                <div><strong>Instalar JUDOMA</strong><span>Abra como app.</span></div>
                <button class="button button-primary" type="button" data-pwa-install hidden>Instalar</button>
                <a class="button button-primary" href="{{ route('app.install') }}" data-pwa-howto>Ver como</a>
                <button class="pwa-banner-close" type="button" data-pwa-dismiss aria-label="Agora não">×</button>
            </aside>
        @endauth
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
