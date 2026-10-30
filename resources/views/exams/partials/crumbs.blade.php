{{-- Caminho de volta nos três níveis dos exames: associação (breadcrumbs), dojo e banca (← Início). --}}
@if($context->level === 'association')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $context->association) }}">{{ $context->association->trade_name }}</a><span>/</span><a href="{{ $context->route('index') }}">Exames</a>@isset($exam)<span>/</span><a href="{{ $context->route('show', ['exam' => $exam]) }}">{{ $exam->name }}</a>@endisset @isset($here)<span>/</span><span>{{ $here }}</span>@endisset</nav>
@else
@include('partials.back-to-dashboard')
<nav class="breadcrumbs">@if($context->dojo)<a href="{{ route('operation.home', $context->dojo) }}">{{ $context->dojo->name }}</a><span>/</span>@endif<a href="{{ $context->route('index') }}">Exames</a>@isset($exam)<span>/</span><a href="{{ $context->route('show', ['exam' => $exam]) }}">{{ $exam->name }}</a>@endisset @isset($here)<span>/</span><span>{{ $here }}</span>@endisset</nav>
@endif
