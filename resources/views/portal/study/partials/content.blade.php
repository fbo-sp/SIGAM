{{-- Conteúdo de estudo da técnica; cada bloco só aparece quando preenchido. --}}
@php($points = $item->keyPointList())
@php($steps = $item->stepList())
@if($points)<div class="card"><h2 class="study-label">O que o professor avalia</h2><ol class="study-points">@foreach($points as $point)<li>{{ $point }}</li>@endforeach</ol></div>@endif
@if($item->common_error)<div class="card study-error"><h2 class="study-label">Cuidado</h2><p>{{ $item->common_error }}</p></div>@endif
@if($item->safety_note)<div class="card study-safety"><h2 class="study-label">Segurança</h2><p>{{ $item->safety_note }}</p></div>@endif
@if($steps)<div class="card"><h2 class="study-label">Passo a passo</h2><ol class="study-steps">@foreach($steps as $step)<li>{{ $step }}</li>@endforeach</ol></div>@endif
