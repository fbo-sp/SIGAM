@props(['axes', 'label'])
@php
    $count = count($axes);
    $size = 340; $center = 170; $radius = 105; $max = 4;
    $point = function (int $index, float $value) use ($count, $center, $radius, $max): array {
        $angle = deg2rad(-90 + 360 * $index / max($count, 1));
        return [round($center + cos($angle) * $radius * $value / $max, 1), round($center + sin($angle) * $radius * $value / $max, 1)];
    };
    $polygon = fn (string $key) => collect($axes)->values()->map(fn ($axis, $i) => implode(',', $point($i, max((float) $axis[$key], 0))))->join(' ');
@endphp
@if($count >= 3)
<figure class="radar">
<svg viewBox="-90 -10 520 {{ $size + 20 }}" role="img" aria-label="{{ $label }}">
@foreach([1, 2, 3, 4] as $ring)<polygon class="radar-ring" points="{{ collect($axes)->values()->map(fn ($axis, $i) => implode(',', $point($i, $ring)))->join(' ') }}"/>@endforeach
@foreach(array_values($axes) as $i => $axis)@php([$x, $y] = $point($i, 4))<line class="radar-ring" x1="{{ $center }}" y1="{{ $center }}" x2="{{ $x }}" y2="{{ $y }}"/>@php([$lx, $ly] = $point($i, 4.75))<text x="{{ $lx }}" y="{{ $ly }}" text-anchor="{{ abs($lx - $center) < 12 ? 'middle' : ($lx > $center ? 'start' : 'end') }}" dominant-baseline="middle">{{ \Illuminate\Support\Str::limit($axis['label'], 18) }}</text>@endforeach
<polygon class="radar-required" points="{{ $polygon('required') }}"/>
<polygon class="radar-current" points="{{ $polygon('current') }}"/>
</svg>
<figcaption><span class="radar-key radar-key-current"></span>Nível atual <span class="radar-key radar-key-required"></span>Exigido</figcaption>
</figure>
@endif
<table class="radar-table @if($count >= 3) sr-only @endif"><caption class="sr-only">{{ $label }}</caption><thead><tr><th>Grupo</th><th>Atual</th><th>Exigido</th></tr></thead><tbody>
@foreach($axes as $axis)<tr><td>{{ $axis['label'] }}</td><td>{{ number_format($axis['current'], 1, ',', '') }}</td><td>{{ number_format($axis['required'], 1, ',', '') }}</td></tr>@endforeach
</tbody></table>
