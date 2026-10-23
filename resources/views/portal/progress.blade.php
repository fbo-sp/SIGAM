@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('title', 'Progresso técnico — '.$name)
@section('content')
<nav class="breadcrumbs" aria-label="Caminho"><a href="{{ route('portal.index') }}">Meu portal</a><span>/</span><a href="{{ route('portal.students.show', $student) }}">{{ $name }}</a><span>/</span><span>Progresso técnico</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · progresso técnico</span><h1>{{ $name }}</h1>
<p>{{ $snapshot['current_rank']?->display_name ?? 'Sem graduação registrada' }}@if($snapshot['next_rank']) · rumo a <strong>{{ $snapshot['next_rank']->display_name }}</strong>@endif</p></div></div>

@if($options->count() > 1)
<nav class="section-tabs" aria-label="Modalidades">@foreach($options as $option)<a href="{{ route('portal.progress', [$student, 'modalidade' => $option->martial_art_id]) }}" @if($option->martial_art_id === $martialArt->id) aria-current="page" @endif>{{ $option->martialArt->name }}</a>@endforeach</nav>
@endif

<section class="section-block" id="quiz"><div class="section-heading"><h2>Quiz teórico</h2><p>Treine quantas vezes quiser. A prova oficial só aparece quando o professor libera e vale uma tentativa.</p></div>
<div class="card list-card">
@if($quizRelease)<div class="list-row"><span><strong>Prova oficial liberada: {{ $quizRelease->rank->display_name }}</strong><small>{{ $quizRelease->attempt ? 'Você já começou; continue de onde parou.' : 'Uma tentativa. Responda com calma antes de enviar.' }}</small></span>
@if($quizRelease->attempt)<a class="button button-primary" href="{{ route('portal.quiz.attempt', [$student, $quizRelease->attempt]) }}">Continuar prova</a>@else<form method="post" action="{{ route('portal.quiz.start', [$student, $martialArt]) }}">@csrf<input type="hidden" name="mode" value="official"><input type="hidden" name="release" value="{{ $quizRelease->id }}"><button class="button button-primary">Fazer prova oficial</button></form>@endif</div>@endif
@if($snapshot['next_rank'])
@php($training = $inProgress->firstWhere('mode', 'training'))
<div class="list-row"><span><strong>Treino para {{ $snapshot['next_rank']->display_name }}</strong><small>Não conta para o acompanhamento. {{ $trainingCount }} treino(s) feitos.</small></span>
@if($training)<a class="button button-secondary" href="{{ route('portal.quiz.attempt', [$student, $training]) }}">Continuar treino</a>@else<form method="post" action="{{ route('portal.quiz.start', [$student, $martialArt]) }}">@csrf<input type="hidden" name="mode" value="training"><button class="button button-secondary">Treinar</button></form>@endif</div>
@endif
@error('quiz')<div class="list-row"><p class="field-error">{{ $message }}</p></div>@enderror
@foreach($attempts as $attempt)<div class="list-row"><span><strong><a href="{{ route('portal.quiz.attempt', [$student, $attempt]) }}">{{ $attempt->mode === 'official' ? 'Prova oficial' : 'Treino' }} · {{ $attempt->rank->display_name }}</a></strong><small>{{ $attempt->finished_at->format('d/m/Y H:i') }} · {{ $attempt->correct_count }} de {{ $attempt->question_count }} acerto(s)</small></span><span class="status-pill {{ $attempt->mode === 'official' && ! $attempt->passed ? 'status-inactive' : '' }}">{{ $attempt->score }}%</span></div>@endforeach
</div></section>

@if($snapshot['next_rank'])
<section class="section-block"><div class="section-heading"><h2>O que falta para {{ $snapshot['next_rank']->display_name }}</h2><p>Itens ainda abaixo do nível pedido no exame, com material para estudar.</p></div>
<div class="card list-card">
@foreach($snapshot['requirements'] as $requirement)<div class="list-row"><span><strong>{{ $requirement['label'] }}</strong><small>{{ $requirement['detail'] }}</small></span><span class="status-pill {{ $requirement['met'] === false ? 'status-inactive' : '' }}">{{ $requirement['met'] === null ? 'Sem dado' : ($requirement['met'] ? 'Atende' : 'Ainda não') }}</span></div>@endforeach
</div>
@foreach($knowledgeLabels as $type => $label)
@if($snapshot['rows']->has($type))
<h3>{{ $label }}: {{ $snapshot['missing'][$type]->count() }} de {{ $snapshot['rows'][$type]->count() }} item(ns) a trabalhar</h3>
@if($snapshot['missing'][$type]->isNotEmpty())
<div class="card list-card">@foreach($snapshot['missing'][$type] as $row)<div class="list-row progress-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') }}@if($row['item']->video_url) · <a href="{{ $row['item']->video_url }}" target="_blank" rel="noopener noreferrer">vídeo</a>@endif</small>@if($row['item']->description)<small>{{ $row['item']->description }}</small>@endif</span><span class="level-gap"><span class="status-pill status-inactive">{{ $levels[$row['current'] ?? 1] }}</span><span aria-hidden="true">→</span><span class="status-pill">{{ $levels[$row['level']] }}</span></span></div>@endforeach</div>
@else
<p>Tudo no nível pedido. Continue praticando.</p>
@endif
@endif
@endforeach
@if($snapshot['rows']->isEmpty())<div class="card empty-state"><p>A associação ainda não definiu o currículo desta graduação.</p></div>@endif
</section>

@if($snapshot['rows']->isNotEmpty())
<section class="section-block"><div class="section-heading"><h2>Perfil técnico</h2><p>Seu nível atual por grupo comparado com o pedido para a próxima graduação.</p></div>
<div class="radar-grid">@foreach($knowledgeLabels as $type => $label)@if($snapshot['radar']->has($type))<article class="card radar-card"><h3>{{ $label }}</h3><x-radar :axes="$snapshot['radar'][$type]" :label="'Perfil técnico: '.$label" /></article>@endif @endforeach</div>
</section>
@endif
@endif

<section class="section-block"><div class="section-heading"><h2>Trabalhado nas aulas</h2><p>Últimos 90 dias: {{ $worked['sessions'] }} aula(s) com presença.</p></div>
@if($worked['axes']->isEmpty())<div class="card empty-state"><p>Nenhuma técnica registrada nas aulas em que você esteve.</p></div>@else
<div class="radar-grid">@foreach($knowledgeLabels as $type => $label)@if($worked['axes']->has($type))<div class="card list-card"><div class="list-row"><span><strong>{{ $label }}</strong></span></div>@foreach($worked['axes'][$type] as $axis)<div class="list-row"><span>{{ $axis['label'] }}</span><span class="status-pill">{{ $axis['sessions'] }} de {{ $worked['sessions'] }} aula(s)</span></div>@endforeach</div>@endif @endforeach</div>@endif
</section>

<section class="section-block"><div class="section-heading"><h2>Evolução</h2><p>Avaliações registradas pelos professores.</p></div>
<div class="card list-card">@forelse($history as $record)<div class="list-row"><span><strong>{{ $record->item->name }}</strong><small>{{ $record->assessed_on->format('d/m/Y') }}</small></span><span class="status-pill">{{ $levels[$record->level] }}</span></div>@empty<div class="empty-state"><p>Nenhuma avaliação ainda.</p></div>@endforelse</div>
</section>
@endsection
