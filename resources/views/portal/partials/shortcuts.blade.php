{{-- Atalhos para as subtelas do portal. --}}
<nav class="portal-shortcuts" aria-label="Atalhos do portal">
@foreach(\App\Http\Controllers\Portal\PortalController::SECTIONS as $key => $label)
@continue($key === 'financeiro' && ! app(\App\Services\Portal\PortalAccessService::class)->financeVisibility($student)['finance'])
@continue($key === 'progresso' && $student->martialArts()->where('status', 'active')->doesntExist())
<a class="card portal-shortcut" href="{{ $key === 'progresso' ? route('portal.progress', $student) : route('portal.students.section', [$student, $key]) }}">@include('portal.partials.icon', ['name' => $key])<span>{{ $label }}</span></a>
@endforeach
@php($unread = app(\App\Services\Portal\PortalAccessService::class)->unreadNotices(auth()->user())['count'])
<a class="card portal-shortcut {{ $unread ? 'has-unread' : '' }}" href="{{ route('notifications.index') }}">@include('portal.partials.icon', ['name' => $unread ? 'avisos-novo' : 'avisos'])<span>Avisos</span>@if($unread)<span class="unread-badge">{{ $unread > 99 ? '99+' : $unread }}<span class="sr-only"> não lido(s)</span></span>@endif</a>
</nav>
