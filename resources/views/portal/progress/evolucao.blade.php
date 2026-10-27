<section class="section-block"><div class="section-heading"><h2 class="sr-only">Evolução</h2><p>Avaliações registradas pelos professores.</p></div>
<div class="card list-card">@forelse($history as $record)<div class="list-row"><span><strong>{{ $record->item->name }}</strong><small>{{ $record->assessed_on->format('d/m/Y') }}</small></span><span class="status-pill">{{ $levels[$record->level] }}</span></div>@empty<div class="empty-state"><p>Nenhuma avaliação ainda.</p></div>@endforelse</div>
</section>
