{{-- Linha do tempo da próxima graduação (portal e ficha do professor). $showNotes mostra a observação interna. --}}
@php($showNotes ??= false)
<section class="section-block"><div class="section-heading"><h2>Itens no nível pedido</h2><p>Parte do currículo de {{ $snapshot['next_rank']?->display_name }} já no nível pedido no exame{{ $timeline['from'] ? ', desde '.$timeline['from']->format('d/m/Y') : '' }}.</p></div>
@if($timeline['overall'] === [])
<div class="card empty-state"><p>Ainda não há avaliações para desenhar a linha do tempo.</p></div>
@else
<div class="card chart-card"><x-timeline-chart :points="$timeline['overall']" :from="$timeline['from']" :ticks="[0 => '0%', 50 => '50%', 100 => '100%']" unit="%" label="Porcentagem do currículo no nível pedido ao longo do tempo" /></div>
@endif
</section>
<section class="section-block"><div class="section-heading"><h2>Por item</h2><p>Abra um item para ver cada avaliação do professor e os dias em que foi treinado em aula.</p></div>
@if($timeline['items']->isEmpty())
<div class="card empty-state"><p>Nenhum item avaliado ou treinado nesta graduação.</p></div>
@else
<div class="progress-groups">@foreach($timeline['items'] as $row)
<details class="card progress-group"><summary><strong>{{ $row['item']->name }}</strong><span @class(['status-pill', 'status-inactive' => $row['missing']])>{{ $row['current'] ? $levels[$row['current']] : 'Sem avaliação' }}</span></summary>
<div class="timeline-item">
<x-timeline-chart :points="$row['points']" :from="$timeline['from']" :min="1" :max="4" :ticks="[1 => '1', 2 => '2', 3 => '3', 4 => '4']" :target="$row['level']" :marks="$row['trained']" :label="'Evolução de '.$row['item']->name" />
<p class="timeline-scale">1 Não iniciado · 2 Em desenvolvimento · 3 Adequado · 4 Dominado. Pedido no exame: {{ $levels[$row['level']] }}.</p>
@if($row['records']->isNotEmpty())<div class="list-card">@foreach($row['records']->reverse() as $record)<div class="list-row"><span><strong>{{ $record->assessed_on->format('d/m/Y') }} · {{ $levels[$record->level] }}</strong><small>{{ $record->teacher?->person?->social_name ?: $record->teacher?->person?->full_name ?: 'Professor' }}</small>@if($record->student_tip)<small class="assessment-tip">Dica: {{ $record->student_tip }}</small>@endif @if($showNotes && $record->notes)<small>Observação interna: {{ $record->notes }}</small>@endif</span></div>@endforeach</div>@endif
</div>
</details>
@endforeach</div>
@endif
</section>
