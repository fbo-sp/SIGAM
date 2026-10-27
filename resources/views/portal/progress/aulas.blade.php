<section class="section-block"><div class="section-heading"><h2 class="sr-only">Trabalhado nas aulas</h2><p>Últimos 90 dias: {{ $worked['sessions'] }} aula(s) com presença.</p></div>
@if($worked['axes']->isEmpty())<div class="card empty-state"><p>Nenhuma técnica registrada nas aulas em que você esteve.</p></div>@else
<div class="radar-grid">@foreach($knowledgeLabels as $type => $label)@if($worked['axes']->has($type))<div class="card list-card"><div class="list-row"><span><strong>{{ $label }}</strong></span></div>@foreach($worked['axes'][$type] as $axis)<div class="list-row"><span>{{ $axis['label'] }}</span><span class="status-pill">{{ $axis['sessions'] }} de {{ $worked['sessions'] }} aula(s)</span></div>@endforeach</div>@endif @endforeach</div>@endif
</section>
