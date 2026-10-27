{{-- Barra inferior do portal no celular: Início, Frequência, Financeiro e Mais (janela com os demais atalhos). --}}
<nav class="portal-tabbar" aria-label="Navegação do portal">
<a href="{{ route('portal.students.show', $student) }}" @if($current === 'inicio') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'inicio'])<span>Início</span></a>
<a href="{{ route('portal.students.section', [$student, 'frequencia']) }}" @if($current === 'frequencia') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'frequencia'])<span>Frequência</span></a>
<a href="{{ route('portal.students.section', [$student, 'financeiro']) }}" @if($current === 'financeiro') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'financeiro'])<span>Financeiro</span></a>
<a href="#portal-mais" data-modal-open="portal-mais" @if($current === 'mais') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'mais'])<span>Mais</span></a>
</nav>
<x-modal id="portal-mais" title="Mais opções">@include('portal.partials.shortcuts')<p class="modal-account"><a class="button button-secondary button-block" href="{{ route('account.show') }}">Minha conta e senha</a></p></x-modal>
