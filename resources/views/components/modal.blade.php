@props(['id', 'title', 'open' => false])
{{-- Janela sobre a página (public/js/common.js abre com data-modal-open). Sem JavaScript o botão leva à âncora e a janela aparece no fluxo da página. --}}
<dialog {{ $attributes->class(['card', 'modal']) }} id="{{ $id }}" aria-labelledby="{{ $id }}-titulo" @if($open) data-open-on-load @endif>
<div class="modal-heading"><h2 id="{{ $id }}-titulo">{{ $title }}</h2><form method="dialog"><button class="button button-secondary">Fechar</button></form></div>
{{ $slot }}
</dialog>
