@if($snapshot['next_rank'] && $timeline)
@include('progression._timeline')
@endif
<section class="section-block"><details class="card disclosure" @if(! $snapshot['next_rank']) open @endif><summary>Todas as avaliações registradas</summary>
<div class="list-card">@forelse($history as $record)<div class="list-row"><span><strong>{{ $record->item->name }}</strong><small>{{ $record->assessed_on->format('d/m/Y') }}</small>@if($record->student_tip)<small class="assessment-tip">Dica: {{ $record->student_tip }}</small>@endif</span><span class="status-pill">{{ $levels[$record->level] }}</span></div>@empty<div class="empty-state"><p>Nenhuma avaliação ainda.</p></div>@endforelse</div>
</details></section>
