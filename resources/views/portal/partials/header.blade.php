{{-- Cabeçalho do portal: troca de aluno (responsável), voltar ao início e título da subtela. --}}
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($switchUrl = fn ($option) => isset($section) && $section !== 'progresso' ? route('portal.students.section', [$option, $section]) : (isset($section) ? route('portal.progress', $option) : route('portal.students.show', $option)))
@if($students->count() > 1)
<nav class="dependent-switcher" aria-label="Trocar aluno"><strong>Consultando:</strong><div>@foreach($students as $option)<a href="{{ $switchUrl($option) }}" @class(['is-current' => $option->is($student)]) aria-current="{{ $option->is($student) ? 'page' : 'false' }}">{{ $option->person->social_name ?: $option->person->full_name }}</a>@endforeach</div></nav>
@endif
@isset($title)
<a class="button button-secondary back-link" href="{{ route('portal.students.show', $student) }}">← Início</a>
<div class="page-heading portal-heading"><div><span class="eyebrow">{{ $name }}</span><h1>{{ $title }}</h1>@isset($subtitle)<p>{{ $subtitle }}</p>@endisset</div></div>
@endisset
