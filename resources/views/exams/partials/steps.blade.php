{{-- Passos do rascunho: dados, participantes, conteúdo e abrir. --}}
@if($context->canManage() && $exam->isEditable())
<nav class="section-tabs" aria-label="Passos do exame">
<a href="{{ $context->route('edit', ['exam' => $exam]) }}" @if($step === 'dados') aria-current="page" @endif>1. Dados</a>
<a href="{{ $context->route('participants', ['exam' => $exam]) }}" @if($step === 'participantes') aria-current="page" @endif>2. Participantes</a>
<a href="{{ $context->route('content', ['exam' => $exam]) }}" @if($step === 'conteudo') aria-current="page" @endif>3. Conteúdo</a>
<a href="{{ $context->route('show', ['exam' => $exam]) }}" @if($step === 'resumo') aria-current="page" @endif>{{ $exam->status === 'draft' ? '4. Revisar e abrir' : 'Resumo' }}</a>
</nav>
@endif
