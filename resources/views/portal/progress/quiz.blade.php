<section class="section-block" id="quiz"><div class="section-heading"><h2 class="sr-only">Quiz teórico</h2><p>Treine quantas vezes quiser. A prova oficial só aparece quando o professor libera e vale uma tentativa.</p></div>
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
