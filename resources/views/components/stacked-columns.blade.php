@props(['months', 'series', 'label'])
{{-- Colunas empilhadas em SVG (a CSP não permite estilo inline): uma coluna por mês, um segmento por série. Cores por posição fixa da série. --}}
@php
    $totals = collect($months)->map(fn ($month) => collect($series)->sum(fn ($row) => $row['values'][$month['key']] ?? 0));
    $max = max($totals->max() ?? 0, 1);
    $plotTop = 12; $plotBottom = 150; $height = $plotBottom - $plotTop; $left = 28; $width = 300;
    $slot = $width / max(count($months), 1); $bar = min(36, $slot * 0.6);
@endphp
<figure {{ $attributes->class(['stacked-columns']) }}>
<svg viewBox="0 0 {{ $left + $width + 4 }} 172" role="img" aria-label="{{ $label }}">
<line class="chart-grid" x1="{{ $left }}" x2="{{ $left + $width }}" y1="{{ $plotTop }}" y2="{{ $plotTop }}"/><text class="chart-axis" x="{{ $left - 6 }}" y="{{ $plotTop + 4 }}" text-anchor="end">{{ $max }}</text>
<line class="chart-baseline" x1="{{ $left }}" x2="{{ $left + $width }}" y1="{{ $plotBottom }}" y2="{{ $plotBottom }}"/><text class="chart-axis" x="{{ $left - 6 }}" y="{{ $plotBottom + 4 }}" text-anchor="end">0</text>
@foreach($months as $i => $month)
@php($x = $left + $slot * $i + ($slot - $bar) / 2)
@php($y = $plotBottom)
@foreach($series as $s => $row)@php($value = $row['values'][$month['key']] ?? 0)@if($value > 0)@php($h = $value / $max * $height)<rect class="series-{{ $s + 1 }}" x="{{ round($x, 2) }}" y="{{ round($y - $h, 2) }}" width="{{ round($bar, 2) }}" height="{{ round(max($h - 2, 1), 2) }}" rx="2"><title>{{ $row['label'] }} · {{ $month['label'] }}: {{ $value }} aula(s)</title></rect>@php($y -= $h)@endif @endforeach
<text class="chart-axis" x="{{ round($left + $slot * $i + $slot / 2, 2) }}" y="166" text-anchor="middle">{{ $month['label'] }}</text>
@endforeach
</svg>
<figcaption class="chart-legend">@foreach($series as $s => $row)<span><svg viewBox="0 0 10 10" aria-hidden="true"><rect class="series-{{ $s + 1 }}" width="10" height="10" rx="2"/></svg>{{ $row['label'] }}</span>@endforeach</figcaption>
</figure>
