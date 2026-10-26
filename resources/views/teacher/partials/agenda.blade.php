{{-- Hoje do professor: chamadas abertas de dias anteriores, aulas de hoje e próximos dias ($agenda de TeacherWorkspaceService). --}}
@php($weekdays = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'])
@if($agenda['pending']->isNotEmpty())
<details class="card pending-attendance"><summary><strong>{{ $agenda['pending']->count() }} chamada(s) aberta(s) de dias anteriores</strong><small>Toque para ver e fechar</small></summary>
<div class="list-card">@foreach($agenda['pending']->sortByDesc(fn ($item) => $item['session']->session_date->format('Y-m-d').$item['session']->start_time) as $item)<a class="list-row" href="{{ $item['url'] }}"><span><strong>{{ $item['session']->classGroup->name }}</strong><small>{{ $weekdays[$item['session']->session_date->dayOfWeek] }} {{ $item['session']->session_date->format('d/m/Y') }} · {{ substr($item['session']->start_time, 0, 5) }} · {{ $item['dojo']?->name }}</small></span><span class="status-pill status-inactive">Abrir chamada</span></a>@endforeach</div>
</details>
@endif
<section class="section-block"><div class="section-heading"><h2>Aulas de hoje</h2></div>
@forelse($agenda['today'] as $item)
<article class="card today-class"><div><strong class="today-time">{{ substr($item['session']->start_time, 0, 5) }}</strong><span><strong>{{ $item['session']->classGroup->name }}</strong><small>{{ $item['dojo']?->name }} · {{ $item['session']->classGroup->enrollments_count }} aluno(s) · {{ mb_strtolower($item['role']) }}</small></span></div>
<a class="button {{ $item['session']->attendance_state === 'open' ? 'button-primary' : 'button-secondary' }}" href="{{ $item['url'] }}">{{ $item['session']->attendance_state === 'open' ? 'Fazer chamada' : 'Ver chamada' }}</a></article>
@empty
<div class="card empty-state"><p>Nenhuma aula sua hoje.</p></div>
@endforelse
</section>
<section class="section-block"><div class="section-heading"><h2>Próximos dias</h2></div>
<div class="card list-card">@forelse($agenda['upcoming'] as $item)<a class="list-row" href="{{ $item['url'] }}"><span><strong>{{ $weekdays[$item['session']->session_date->dayOfWeek] }} {{ $item['session']->session_date->format('d/m') }} · {{ substr($item['session']->start_time, 0, 5) }}</strong><small>{{ $item['session']->classGroup->name }} · {{ $item['dojo']?->name }}</small></span><span class="status-pill">Abrir</span></a>@empty<div class="empty-state"><p>Nenhuma aula nos próximos sete dias.</p></div>@endforelse</div>
</section>
