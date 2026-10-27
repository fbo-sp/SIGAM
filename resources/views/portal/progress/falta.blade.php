@if(! $snapshot['next_rank'])
<div class="card empty-state"><p>Você está na última graduação cadastrada.</p></div>
@else
<section class="section-block"><div class="section-heading"><h2>Requisitos do exame</h2></div>
<div class="card list-card">
@foreach($snapshot['requirements'] as $requirement)<div class="list-row"><span><strong>{{ $requirement['label'] }}</strong><small>{{ $requirement['detail'] }}</small></span><span class="status-pill {{ $requirement['met'] === false ? 'status-inactive' : '' }}">{{ $requirement['met'] === null ? 'Sem dado' : ($requirement['met'] ? 'Atende' : 'Ainda não') }}</span></div>@endforeach
</div></section>
@if($snapshot['rows']->isEmpty())<div class="card empty-state"><p>A associação ainda não definiu o currículo desta graduação.</p></div>@endif
@foreach($knowledgeLabels as $type => $label)
@if($snapshot['rows']->has($type))
<section class="section-block"><div class="section-heading"><h2>{{ $label }}</h2><p>{{ $snapshot['missing'][$type]->count() }} de {{ $snapshot['rows'][$type]->count() }} item(ns) a trabalhar.</p></div>
@if($snapshot['missing'][$type]->isNotEmpty())
<div class="progress-groups">@foreach($snapshot['missing'][$type]->groupBy('axis') as $axis => $rows)<details class="card progress-group"><summary><strong>{{ $axis }}</strong><span class="status-pill status-inactive">{{ $rows->count() }} a trabalhar</span></summary><div class="list-card">@foreach($rows as $row)<div class="list-row progress-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') }}@if($row['item']->video_url) · <a href="{{ $row['item']->video_url }}" target="_blank" rel="noopener noreferrer">vídeo</a>@endif</small>@if($row['item']->description)<small>{{ $row['item']->description }}</small>@endif</span><span class="level-gap"><span class="status-pill status-inactive">{{ $levels[$row['current'] ?? 1] }}</span><span aria-hidden="true">→</span><span class="status-pill">{{ $levels[$row['level']] }}</span></span></div>@endforeach</div></details>@endforeach</div>
@else
<p>Tudo no nível pedido. Continue praticando.</p>
@endif
</section>
@endif
@endforeach
@endif
