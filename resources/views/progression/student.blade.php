@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('title', 'Acompanhamento — '.$name)
@section('content')
@if($dojo)
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>{{ $name }}</span><span>/</span><span>Acompanhamento</span></nav>
@else
<nav class="breadcrumbs"><a href="{{ route('associations.students.index', $association) }}">Alunos</a><span>/</span><a href="{{ route('associations.students.show', [$association, $student]) }}">{{ $name }}</a><span>/</span><span>Acompanhamento</span></nav>
@endif
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · acompanhamento técnico</span><h1>{{ $name }}</h1>
<p>{{ $snapshot['current_rank']?->display_name ?? 'Sem graduação registrada' }}@if($snapshot['next_rank']) · rumo a <strong>{{ $snapshot['next_rank']->display_name }}</strong>@endif</p></div>
@if($canAssess && $snapshot['next_rank'])<a class="button button-primary" href="{{ route('operation.progression.assess', [$dojo, $student, $martialArt]) }}">Avaliar</a>@endif</div>

@if($options->count() > 1)
<nav class="section-tabs" aria-label="Modalidades">@foreach($options as $option)<a href="{{ $switchUrl($option->martial_art_id) }}" @if($option->martial_art_id === $martialArt->id) aria-current="page" @endif>{{ $option->martialArt->name }}</a>@endforeach</nav>
@endif

@if(! $snapshot['next_rank'])
<div class="card empty-state"><h2>Sem próxima graduação</h2><p>O aluno já está na última graduação cadastrada desta modalidade.</p></div>
@else
<section class="section-block"><div class="section-heading"><h2>O que falta para {{ $snapshot['next_rank']->display_name }}</h2><p>Itens abaixo do nível exigido no exame. Não há percentual: a lista mostra o que trabalhar.</p></div>
<div class="card list-card">
@foreach($snapshot['requirements'] as $requirement)<div class="list-row"><span><strong>{{ $requirement['label'] }}</strong><small>{{ $requirement['detail'] }}</small></span><span class="status-pill {{ $requirement['met'] === false ? 'status-inactive' : '' }}">{{ $requirement['met'] === null ? 'Sem dado' : ($requirement['met'] ? 'Atende' : 'Ainda não') }}</span></div>@endforeach
@if($snapshot['rows']->isEmpty())<div class="list-row"><span><strong>Currículo não definido</strong><small>A associação ainda não definiu o que esta graduação cobra.</small></span></div>@endif
</div>
@foreach($knowledgeLabels as $type => $label)
@if($snapshot['rows']->has($type))
<h3>{{ $label }}: {{ $snapshot['missing'][$type]->count() }} de {{ $snapshot['rows'][$type]->count() }} item(ns) abaixo do exigido</h3>
@if($snapshot['missing'][$type]->isNotEmpty())
<div class="card list-card">@foreach($snapshot['missing'][$type] as $row)<div class="list-row progress-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') }}</small></span><span class="level-gap"><span class="status-pill status-inactive">{{ $levels[$row['current'] ?? 1] }}</span><span aria-hidden="true">→</span><span class="status-pill">{{ $levels[$row['level']] }}</span></span></div>@endforeach</div>
@else
<p>Todos os itens {{ $type === 'theoretical' ? 'teóricos' : 'práticos' }} estão no nível exigido.</p>
@endif
@endif
@endforeach
</section>

@if($snapshot['rows']->isNotEmpty())
<section class="section-block"><div class="section-heading"><h2>Perfil técnico</h2><p>Média do nível atual por grupo, comparada com o exigido pela próxima graduação (1 = não iniciado, 4 = dominado).</p></div>
<div class="radar-grid">@foreach($knowledgeLabels as $type => $label)@if($snapshot['radar']->has($type))<article class="card radar-card"><h3>{{ $label }}</h3><x-radar :axes="$snapshot['radar'][$type]" :label="'Perfil técnico: '.$label" /></article>@endif @endforeach</div>
</section>

<section class="section-block"><details class="card disclosure"><summary>Currículo completo de {{ $snapshot['next_rank']->display_name }} com o nível atual</summary>
@foreach($knowledgeLabels as $type => $label)@if($snapshot['rows']->has($type))<h3>{{ $label }}</h3><div class="list-card">@foreach($snapshot['rows'][$type] as $row)<div class="list-row progress-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') }}@if($row['assessed_on']) · avaliado em {{ $row['assessed_on']->format('d/m/Y') }}@endif @if($row['item']->video_url) · <a href="{{ $row['item']->video_url }}" target="_blank" rel="noopener noreferrer">vídeo</a>@endif</small></span><span class="level-gap"><span class="status-pill {{ $row['missing'] ? 'status-inactive' : '' }}">{{ $row['current'] ? $levels[$row['current']] : 'Sem avaliação' }}</span><small>exige {{ $levels[$row['level']] }}</small></span></div>@endforeach</div>@endif @endforeach
</details></section>
@endif
@endif

<section class="section-block"><div class="section-heading"><h2>Evolução</h2><p>Cada avaliação fica registrada; o nível atual é o último registro de cada item.</p></div>
<div class="card list-card">@forelse($history as $record)<div class="list-row"><span><strong>{{ $record->item->name }}</strong><small>{{ $record->assessed_on->format('d/m/Y') }} · {{ $record->teacher?->person?->social_name ?: $record->teacher?->person?->full_name ?: 'Professor' }}@if($record->notes) · {{ $record->notes }}@endif</small></span><span class="status-pill">{{ $levels[$record->level] }}</span></div>@empty<div class="empty-state"><h2>Nenhuma avaliação ainda</h2><p>Os professores das turmas do aluno registram as avaliações pelo botão Avaliar.</p></div>@endforelse</div>
</section>
@endsection
