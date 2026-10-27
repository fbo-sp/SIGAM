@props(['points', 'from' => null, 'min' => 0, 'max' => 100, 'ticks' => [], 'target' => null, 'marks' => [], 'label', 'unit' => ''])
{{-- Linha do tempo em degraus (SVG, sem estilo inline por causa da CSP): cada ponto vale até o próximo. Opcionalmente uma linha tracejada no alvo e marcas de treino no rodapé. --}}
@php
    $points = collect($points)->sortBy(fn ($p) => $p['date']->timestamp)->values();
    $start = ($from ?? $points->first()['date'] ?? today())->copy()->startOfDay();
    $end = today()->copy()->startOfDay();
    foreach ($marks as $mark) { if ($mark->lt($start)) { $start = $mark->copy()->startOfDay(); } }
    if ($points->isNotEmpty() && $points->first()['date']->lt($start)) { $start = $points->first()['date']->copy()->startOfDay(); }
    $span = max($start->diffInDays($end), 1);
    $left = 34; $right = 334; $top = 10; $bottom = 120; 
    $x = fn ($date) => round($left + ($right - $left) * min(max($start->diffInDays($date->copy()->startOfDay(), false), 0), $span) / $span, 2);
    $y = fn ($value) => round($bottom - ($bottom - $top) * (($value - $min) / max($max - $min, 1)), 2);
    $path = '';
    foreach ($points as $i => $p) {
        $px = $x($p['date']); $py = $y($p['value']);
        $path .= $i === 0 ? "M{$px},{$py}" : " H{$px} V{$py}";
    }
    if ($points->isNotEmpty()) { $path .= ' H'.$x($end); }
    $months = [];
    for ($m = $start->copy()->startOfMonth()->addMonth(); $m->lte($end); $m->addMonth()) { $months[] = $m->copy(); }
    if (count($months) > 6) { $step = (int) ceil(count($months) / 6); $months = array_values(array_filter($months, fn ($m, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH)); }
    $monthNames = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
@endphp
<figure {{ $attributes->class(['timeline-chart']) }}>
<svg viewBox="0 0 340 158" role="img" aria-label="{{ $label }}">
@foreach($ticks as $value => $text)<line class="chart-grid" x1="{{ $left }}" x2="{{ $right }}" y1="{{ $y($value) }}" y2="{{ $y($value) }}"/><text class="chart-axis" x="{{ $left - 5 }}" y="{{ $y($value) + 4 }}" text-anchor="end">{{ $text }}</text>@endforeach
<line class="chart-baseline" x1="{{ $left }}" x2="{{ $right }}" y1="{{ $bottom }}" y2="{{ $bottom }}"/>
@foreach($months as $month)<line class="chart-grid" x1="{{ $x($month) }}" x2="{{ $x($month) }}" y1="{{ $top }}" y2="{{ $bottom }}"/><text class="chart-axis" x="{{ $x($month) }}" y="{{ $bottom + 21 }}" text-anchor="middle">{{ $monthNames[$month->month] }}</text>@endforeach
<text class="chart-axis" x="{{ $left }}" y="{{ $bottom + 33 }}" text-anchor="start">{{ $start->format('d/m/y') }}</text><text class="chart-axis" x="{{ $right }}" y="{{ $bottom + 33 }}" text-anchor="end">hoje</text>
@if($target !== null)<line class="chart-target" x1="{{ $left }}" x2="{{ $right }}" y1="{{ $y($target) }}" y2="{{ $y($target) }}"/>@endif
@if($path !== '')<path class="chart-line" d="{{ $path }}"/>@endif
@foreach($points as $p)@if(! ($p['edge'] ?? false))<circle class="chart-dot" cx="{{ $x($p['date']) }}" cy="{{ $y($p['value']) }}" r="3.5"><title>{{ $p['date']->format('d/m/Y') }}: {{ $p['title'] ?? $p['value'].$unit }}</title></circle>@endif @endforeach
@foreach($marks as $mark)<circle class="chart-mark" cx="{{ $x($mark) }}" cy="{{ $bottom + 7 }}" r="3"><title>Treinado em aula em {{ $mark->format('d/m/Y') }}</title></circle>@endforeach
</svg>
@if($target !== null || $marks !== [])<figcaption class="chart-legend"><span><svg viewBox="0 0 16 10" aria-hidden="true"><path class="chart-line" d="M0,5 H16"/></svg>Avaliação</span>@if($target !== null)<span><svg viewBox="0 0 16 10" aria-hidden="true"><path class="chart-target" d="M0,5 H16"/></svg>Pedido no exame</span>@endif @if($marks !== [])<span><svg viewBox="0 0 10 10" aria-hidden="true"><circle class="chart-mark" cx="5" cy="5" r="3"/></svg>Treinado em aula</span>@endif</figcaption>@endif
</figure>
