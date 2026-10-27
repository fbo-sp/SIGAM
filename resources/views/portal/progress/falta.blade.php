@php($statusLabels = \App\Services\Progression\StudentProgressService::STATUS_LABELS)
@php($statusHelp = ['improve' => 'O professor avaliou abaixo do pedido no exame. Veja a dica e continue praticando.', 'untrained' => 'Ainda não apareceu numa aula em que você estava presente desde que entrou na faixa atual.', 'awaiting' => 'Você já treinou em aula; falta o professor avaliar.'])
@if(! $snapshot['next_rank'])
<div class="card empty-state"><p>Você está na última graduação cadastrada.</p></div>
@else
<section class="section-block"><div class="section-heading"><h2>Requisitos do exame</h2></div>
<div class="card list-card">
@foreach($snapshot['requirements'] as $requirement)<div class="list-row"><span><strong>{{ $requirement['label'] }}</strong><small>{{ $requirement['detail'] }}</small></span><span class="status-pill {{ $requirement['met'] === false ? 'status-inactive' : '' }}">{{ $requirement['met'] === null ? 'Sem dado' : ($requirement['met'] ? 'Atende' : 'Ainda não') }}</span></div>@endforeach
</div></section>
@if($snapshot['rows']->isEmpty())<div class="card empty-state"><p>A associação ainda não definiu o currículo desta graduação.</p></div>
@else
@php($all = $snapshot['rows']->flatten(1))
@php($missing = $all->where('missing', true))
<p class="progress-total">{{ $all->count() - $missing->count() }} de {{ $all->count() }} item(ns) já no nível pedido.</p>
@if($missing->isEmpty())<div class="card empty-state"><p>Tudo no nível pedido. Continue praticando.</p></div>@endif
@foreach($statusLabels as $status => $statusLabel)
@php($set = $missing->where('status', $status))
@continue($set->isEmpty())
<section class="section-block progress-status-{{ $status }}"><div class="section-heading"><h2>{{ $statusLabel }} ({{ $set->count() }})</h2><p>{{ $statusHelp[$status] }}</p></div>
<div class="progress-groups">@foreach($set->groupBy('axis') as $axis => $rows)<details class="card progress-group"><summary><strong>{{ $axis }}</strong><span class="status-pill status-inactive">{{ $rows->count() }}</span></summary><div class="list-card">@foreach($rows as $row)<div class="list-row progress-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') }}@if($row['item']->video_url) · <a href="{{ $row['item']->video_url }}" target="_blank" rel="noopener noreferrer">vídeo</a>@endif</small>@if($status === 'improve' && $row['assessed_on'])<small>Avaliado em {{ $row['assessed_on']->format('d/m/Y') }}@if($row['trained_on'] && $row['trained_on']->gt($row['assessed_on'])) · treinado de novo em {{ $row['trained_on']->format('d/m/Y') }}@endif</small>@endif @if($status === 'awaiting' && $row['trained_on'])<small>Treinado em aula em {{ $row['trained_on']->format('d/m/Y') }}</small>@endif @if($status === 'improve' && $row['tip'])<small class="assessment-tip">Dica do professor: {{ $row['tip'] }}</small>@endif @if($row['item']->description)<small>{{ $row['item']->description }}</small>@endif</span><span class="level-gap"><span class="status-pill status-inactive">{{ $levels[$row['current'] ?? 1] }}</span><span aria-hidden="true">→</span><span class="status-pill">{{ $levels[$row['level']] }}</span></span></div>@endforeach</div></details>@endforeach</div>
</section>
@endforeach
@endif
@endif
