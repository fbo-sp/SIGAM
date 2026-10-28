{{-- Cabeçalho da técnica (portal e consulta do professor): faixa, grupo, nome, tradução, explicação e botão da ficha. --}}
<div class="card study-head"><div class="study-meta">@if($entry['first_rank'])<span class="study-belt">A partir de {{ $entry['first_rank']->display_name }}</span>@endif<span class="study-pill">{{ $entry['group'] }}</span></div>
<h1>{{ $item->name }}</h1>@if($item->translation || $item->original_name)<p class="study-translation">{{ collect([$item->translation, $item->original_name])->filter()->join(' · ') }}</p>@endif
@if($item->description)<p class="study-what">{{ $item->description }}</p>@endif
@if($item->sheet_path)<a class="button button-secondary study-sheet-button" href="{{ route('techniques.sheet', $item) }}" target="_blank" rel="noopener">@include('portal.partials.icon', ['name' => 'estudar'])<span>Abrir ficha da técnica{{ $item->sheet_mime === 'application/pdf' ? ' (PDF)' : '' }}</span></a>@endif</div>
