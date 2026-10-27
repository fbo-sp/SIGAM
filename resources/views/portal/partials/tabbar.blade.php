{{-- Barra inferior do portal no celular: Início, Frequência, Financeiro (ou Turmas, para quem não tem financeiro) e Mais (janela com os demais atalhos). --}}
@php($showFinance = app(\App\Services\Portal\PortalAccessService::class)->financeVisibility($student)['finance'])
@php($current = $current === 'turmas' && $showFinance ? 'mais' : $current)
<nav class="portal-tabbar" aria-label="Navegação do portal">
<a href="{{ route('portal.students.show', $student) }}" @if($current === 'inicio') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'inicio'])<span>Início</span></a>
<a href="{{ route('portal.students.section', [$student, 'frequencia']) }}" @if($current === 'frequencia') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'frequencia'])<span>Frequência</span></a>
@if($showFinance)<a href="{{ route('portal.students.section', [$student, 'financeiro']) }}" @if($current === 'financeiro') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'financeiro'])<span>Financeiro</span></a>@else<a href="{{ route('portal.students.section', [$student, 'turmas']) }}" @if($current === 'turmas') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'turmas'])<span>Turmas</span></a>@endif
<a href="#portal-mais" data-modal-open="portal-mais" @if($current === 'mais') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'mais'])<span>Mais</span></a>
</nav>
<x-modal id="portal-mais" title="Mais opções">@include('portal.partials.shortcuts')</x-modal>
