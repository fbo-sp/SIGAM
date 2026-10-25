@props(['percent', 'tone', 'label' => null])
{{-- Barra em SVG: a CSP (style-src 'self') não permite largura em atributo style. --}}
<span {{ $attributes->class(['coverage-bar']) }}><svg viewBox="0 0 100 8" preserveAspectRatio="none" role="img" aria-label="{{ $label ? $label.': ' : '' }}{{ $percent }}% trabalhado"><rect class="coverage-track" width="100" height="8" rx="2"/>@if($percent > 0)<rect class="coverage-fill coverage-{{ $tone }}" width="{{ $percent }}" height="8" rx="2"/>@endif</svg><strong class="coverage-{{ $tone }}-text">{{ $percent }}%</strong></span>
