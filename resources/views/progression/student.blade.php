@extends('layouts.app')
@php($name = $student->person->social_name ?: $student->person->full_name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('title', 'Acompanhamento — '.$name)
@section('content')
@if($dojo)
@include('partials.back-to-dashboard')
<nav class="breadcrumbs"><a href="{{ route('operation.home', $dojo) }}">{{ $dojo->name }}</a><span>/</span><span>{{ $name }}</span><span>/</span><span>Acompanhamento</span></nav>
@else
<nav class="breadcrumbs"><a href="{{ route('associations.students.index', $association) }}">Alunos</a><span>/</span><a href="{{ route('associations.students.show', [$association, $student]) }}">{{ $name }}</a><span>/</span><span>Acompanhamento</span></nav>
@endif
<div class="page-heading"><div><span class="eyebrow">{{ $martialArt->name }} · acompanhamento técnico</span><h1>{{ $name }}</h1>
@if($dojo)@php($pendingItems = app(\App\Services\People\PendingDataService::class)->forStudent($student))
@if($pendingItems)@include('people.partials.pending-banner', ['items' => $pendingItems, 'title' => 'Cadastro incompleto', 'url' => app(\App\Services\Access\OperationalScope::class)->canDojo(auth()->user(), $dojo, 'student.manage') ? route('operation.students.person.edit', [$dojo, $student]) : null])@endif @endif
<p>{{ $snapshot['current_rank']?->display_name ?? 'Sem graduação registrada' }}@if($snapshot['next_rank']) · rumo a <strong>{{ $snapshot['next_rank']->display_name }}</strong>@endif</p></div>
<div class="row-actions">@if($dojo && app(\App\Services\Sports\GuardianService::class)->canManage(auth()->user(), $student, $dojo))<a class="button button-secondary" href="{{ route('operation.students.guardians.index', [$dojo, $student]) }}">Responsáveis</a>@endif @if($canAssess && $snapshot['next_rank'])<a class="button button-primary" href="{{ route('operation.progression.assess', [$dojo, $student, $martialArt]) }}">Avaliar</a>@endif</div></div>

@if($options->count() > 1)
<nav class="section-tabs" aria-label="Modalidades">@foreach($options as $option)<a href="{{ $switchUrl($option->martial_art_id) }}" @if($option->martial_art_id === $martialArt->id) aria-current="page" @endif>{{ $option->martialArt->name }}</a>@endforeach</nav>
@endif

@if(! $snapshot['next_rank'])
<div class="card empty-state"><h2>Sem próxima graduação</h2><p>O aluno já está na última graduação cadastrada desta modalidade.</p></div>
@else
<section class="section-block"><div class="section-heading"><h2>O que falta para {{ $snapshot['next_rank']->display_name }}</h2><p>Itens abaixo do nível exigido no exame: {{ $snapshot['statuses']['improve'] }} a melhorar, {{ $snapshot['statuses']['untrained'] }} não treinado(s) desde a faixa atual e {{ $snapshot['statuses']['awaiting'] }} aguardando avaliação.</p></div>
<div class="card list-card">
@foreach($snapshot['requirements'] as $requirement)<div class="list-row"><span><strong>{{ $requirement['label'] }}</strong><small>{{ $requirement['detail'] }}</small></span><span class="status-pill {{ $requirement['met'] === false ? 'status-inactive' : '' }}">{{ $requirement['met'] === null ? 'Sem dado' : ($requirement['met'] ? 'Atende' : 'Ainda não') }}</span></div>@endforeach
@if($snapshot['rows']->isEmpty())<div class="list-row"><span><strong>Currículo não definido</strong><small>A associação ainda não definiu o que esta graduação cobra.</small></span></div>@endif
</div>
@foreach($knowledgeLabels as $type => $label)
@if($snapshot['rows']->has($type))
<h3>{{ $label }}: {{ $snapshot['missing'][$type]->count() }} de {{ $snapshot['rows'][$type]->count() }} item(ns) abaixo do exigido</h3>
@if($snapshot['missing'][$type]->isNotEmpty())
<div class="card list-card">@foreach($snapshot['missing'][$type] as $row)<div class="list-row progress-row"><span><strong>{{ $row['item']->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') }}</small><small @class(['progress-status', 'is-'.$row['status']])>{{ \App\Services\Progression\StudentProgressService::STATUS_LABELS[$row['status']] ?? '' }}@if($row['status'] === 'improve' && $row['assessed_on']) · avaliado em {{ $row['assessed_on']->format('d/m/Y') }}@endif @if($row['trained_on']) · treinado em {{ $row['trained_on']->format('d/m/Y') }}@endif @if(isset($studied[$row['item']->id])) · estudou em casa {{ $studied[$row['item']->id]['count'] }}x, última em {{ $studied[$row['item']->id]['last']->format('d/m') }}@endif</small>@if($row['tip'] && $row['status'] === 'improve')<small class="assessment-tip">Dica ao aluno: {{ $row['tip'] }}</small>@endif</span><span class="level-gap"><span class="status-pill status-inactive">{{ $levels[$row['current'] ?? 1] }}</span><span aria-hidden="true">→</span><span class="status-pill">{{ $levels[$row['level']] }}</span></span></div>@endforeach</div>
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

<section class="section-block" id="quiz"><div class="section-heading"><h2>Quiz teórico</h2><p>O aluno treina à vontade pelo portal ({{ $trainingCount }} treino(s) feitos). A prova oficial vale uma tentativa por liberação do professor; aprovada, marca os itens teóricos das perguntas como Adequado.</p></div>
<div class="card list-card">
@if($quizRelease)<div class="list-row"><span><strong>Prova oficial liberada para {{ $quizRelease->rank->display_name }}</strong><small>Liberada em {{ $quizRelease->created_at->format('d/m/Y') }}{{ $quizRelease->attempt ? ' · em andamento' : ' · aguardando o aluno' }}</small></span>
@if($canAssess && ! $quizRelease->attempt)<form method="post" action="{{ route('operation.progression.quiz.cancel', [$dojo, $student, $quizRelease]) }}">@csrf<button class="button button-secondary">Cancelar liberação</button></form>@endif</div>
@elseif($canAssess && $snapshot['next_rank'])<div class="list-row"><span><strong>Nenhuma prova oficial liberada</strong><small>Libere quando o aluno estiver pronto para a parte teórica de {{ $snapshot['next_rank']->display_name }}.</small></span><form method="post" action="{{ route('operation.progression.quiz.release', [$dojo, $student, $martialArt]) }}">@csrf<button class="button button-primary">Liberar prova oficial</button></form></div>@endif
@error('quiz')<div class="list-row"><p class="field-error">{{ $message }}</p></div>@enderror
@forelse($quizAttempts as $attempt)<div class="list-row"><span><strong>Prova oficial · {{ $attempt->rank->display_name }}</strong><small>{{ $attempt->finished_at->format('d/m/Y') }} · {{ $attempt->correct_count }} de {{ $attempt->question_count }} acerto(s), mínimo {{ $attempt->minimum_score }}%</small></span><span class="status-pill {{ $attempt->passed ? '' : 'status-inactive' }}">{{ $attempt->score }}% · {{ $attempt->passed ? 'Aprovado' : 'Abaixo do mínimo' }}</span></div>
@empty @if(! $quizRelease && ! $canAssess)<div class="list-row"><span><small>Nenhuma prova oficial feita.</small></span></div>@endif @endforelse
</div></section>

<section class="section-block"><div class="section-heading"><h2>Trabalhado nas aulas</h2><p>Últimos 90 dias: {{ $worked['sessions'] }} aula(s) com presença. Conta só a aula em que o aluno estava presente.</p></div>
@if($worked['axes']->isEmpty())<div class="card empty-state"><p>Nenhuma técnica marcada nas aulas em que o aluno esteve presente.</p></div>@else
<div class="radar-grid">@foreach($knowledgeLabels as $type => $label)@if($worked['axes']->has($type))<div class="card list-card"><div class="list-row"><span><strong>{{ $label }}</strong></span></div>@foreach($worked['axes'][$type] as $axis)<div class="list-row"><span>{{ $axis['label'] }}</span><span class="status-pill">{{ $axis['sessions'] }} de {{ $worked['sessions'] }} aula(s)</span></div>@endforeach</div>@endif @endforeach</div>@endif
</section>

@php($studiedItems = collect($studied ?? [])->sortByDesc(fn ($log) => $log['last'])->take(12))
<section class="section-block"><div class="section-heading"><h2>Estudo em casa</h2><p>O que o aluno marcou como estudado na área Estudar do portal (um registro por dia).</p></div>
@if($studiedItems->isEmpty())<div class="card empty-state"><p>O aluno ainda não registrou estudo no portal.</p></div>@else
<div class="card list-card">@foreach($studiedItems as $log)<div class="list-row"><span>{{ $log['name'] }}</span><span class="status-pill">{{ $log['count'] }}x · última {{ $log['last']->format('d/m') }}</span></div>@endforeach</div>@endif
</section>

@if($timeline)
@include('progression._timeline', ['showNotes' => true])
@endif
<section class="section-block"><div class="section-heading"><h2>Todas as avaliações</h2><p>Cada avaliação fica registrada, inclusive quando o nível se mantém; o nível atual é o último registro de cada item.</p></div>
<div class="card list-card">@forelse($history as $record)<div class="list-row"><span><strong>{{ $record->item->name }}</strong><small>{{ $record->assessed_on->format('d/m/Y') }} · {{ $record->teacher?->person?->social_name ?: $record->teacher?->person?->full_name ?: 'Professor' }}@if($record->notes) · {{ $record->notes }}@endif</small>@if($record->student_tip)<small class="assessment-tip">Dica ao aluno: {{ $record->student_tip }}</small>@endif</span><span class="status-pill">{{ $levels[$record->level] }}</span></div>@empty<div class="empty-state"><h2>Nenhuma avaliação ainda</h2><p>Os professores das turmas do aluno registram as avaliações pelo botão Avaliar.</p></div>@endforelse</div>
</section>
@endsection
