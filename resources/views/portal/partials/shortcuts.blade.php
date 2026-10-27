{{-- Atalhos para as subtelas do portal. --}}
<nav class="portal-shortcuts" aria-label="Atalhos do portal">
@foreach(\App\Http\Controllers\Portal\PortalController::SECTIONS as $key => $label)
@continue($key === 'financeiro' && ! app(\App\Services\Portal\PortalAccessService::class)->financeVisibility($student)['finance'])
@continue($key === 'progresso' && $student->martialArts()->where('status', 'active')->doesntExist())
<a class="card portal-shortcut" href="{{ $key === 'progresso' ? route('portal.progress', $student) : route('portal.students.section', [$student, $key]) }}">@include('portal.partials.icon', ['name' => $key])<span>{{ $label }}</span></a>
@endforeach
<a class="card portal-shortcut" href="{{ route('notifications.index') }}">@include('portal.partials.icon', ['name' => 'avisos'])<span>Avisos</span></a>
</nav>
