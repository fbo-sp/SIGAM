@props(['person', 'size' => 'thumb'])
{{-- Miniatura redonda; sem foto mostra as iniciais. Na lista o nome já está ao lado, então a imagem é decorativa. --}}
@php($url = $person->photoUrl($size))
@php($pixels = $size === 'medium' ? 160 : 40)
<span {{ $attributes->class(['person-photo', 'person-photo-'.$size]) }} @if(! $url) aria-hidden="true" @endif>@if($url)<img src="{{ $url }}" alt="{{ $size === 'medium' ? 'Foto de '.($person->social_name ?: $person->full_name) : '' }}" width="{{ $pixels }}" height="{{ $pixels }}" loading="lazy" decoding="async">@else{{ $person->initials() }}@endif</span>
