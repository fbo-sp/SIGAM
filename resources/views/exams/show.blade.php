@extends('layouts.app')
@section('title', $exam->name.' — JUDOMA')
@section('content')
@include('exams.partials.crumbs', ['here' => null])
<div class="page-heading"><div><span class="eyebrow">{{ $exam->martialArt->name }} · {{ \App\Models\RankExam::SCOPES[$exam->scope_type] }}</span><h1>{{ $exam->name }}</h1><p>{{ $exam->starts_on->format('d/m/Y') }} a {{ $exam->ends_on->format('d/m/Y') }} · média mínima {{ number_format((float) $exam->minimum_average, 1, ',', '') }} · @include('exams.partials.status')</p></div>@if($context->canManage() && $exam->isEditable())<a class="button button-secondary" href="{{ $context->route('edit', ['exam' => $exam]) }}">Editar dados</a>@endif</div>
@include('exams.partials.steps', ['step' => 'resumo'])
@error('exam')<div class="alert alert-danger" role="alert">@foreach($errors->get('exam') as $message)<span>{{ $message }}</span>@endforeach</div>@enderror

<dl class="card details-grid exam-details">
<div><dt>Para quem</dt><dd>{{ $exam->scope_type === 'student' ? ($exam->student?->person?->full_name ?? 'Aluno') : $exam->scopeLabel() }}</dd></div>
<div><dt>Banca</dt><dd>@if($exam->class_teachers_examine)Professores das turmas dos alunos @endif @if($exam->class_teachers_examine && $exam->examiners->isNotEmpty()) e @endif {{ $exam->examiners->map(fn ($teacher) => $teacher->person->social_name ?: $teacher->person->full_name)->join(', ') }}@if(! $exam->class_teachers_examine && $exam->examiners->isEmpty())Ninguém escolhido ainda @endif</dd></div>
<div><dt>Prova teórica</dt><dd>{{ $exam->quiz_enabled ? $exam->quiz_question_count.' perguntas'.($exam->quiz_time_limit_minutes ? ', '.$exam->quiz_time_limit_minutes.' min' : ', sem tempo limite') : 'Sem quiz' }}</dd></div>
@if($exam->justification)<div><dt>Justificativa</dt><dd>{{ $exam->justification }}</dd></div>@endif
@if($exam->status === 'cancelled')<div><dt>Cancelado</dt><dd>{{ $exam->cancelled_at?->format('d/m/Y H:i') }} · {{ $exam->cancel_reason }}</dd></div>@endif
</dl>

<section class="section-block" aria-labelledby="exam-conteudo"><div class="section-heading"><h2 id="exam-conteudo">Conteúdo por faixa</h2><p>Cada aluno é avaliado só no conteúdo da faixa que vai receber.</p></div>
@if($ranks->isEmpty())
<div class="card empty-state"><p>Nenhum aluno inscrito ainda.</p>@if($context->canManage() && $exam->isEditable())<a class="card-link" href="{{ $context->route('participants', ['exam' => $exam]) }}">Escolher participantes</a>@endif</div>
@else
<div class="card list-card">@foreach($ranks as $row)<a class="list-row" href="{{ $context->route('content', ['exam' => $exam, 'rank' => $row['rank']]) }}"><span><strong>{{ $row['rank']->display_name ?: $row['rank']->name }}</strong><small>{{ $row['students'] }} aluno(s)</small></span><span class="status-pill">Ver conteúdo</span></a>@endforeach</div>
@endif
</section>

<section class="section-block" aria-labelledby="exam-participantes"><div class="section-heading"><h2 id="exam-participantes">Participantes ({{ $participants->count() }})</h2>@if($context->canManage() && $exam->isEditable())<a class="button button-secondary" href="{{ $context->route('participants', ['exam' => $exam]) }}">Alterar participantes</a>@endif</div>
@if($participants->isNotEmpty())
<div class="card list-card">@foreach($participants as $participant)<div class="list-row"><span class="person-cell"><x-person-photo :person="$participant->student->person" /><span><strong>{{ $participant->student->person->social_name ?: $participant->student->person->full_name }}</strong><small>{{ $participant->currentRank?->name ?? 'Sem faixa' }} → {{ $participant->targetRank->name }}@if($participant->inclusion_reason) · incluído: {{ $participant->inclusion_reason }}@endif</small></span></span><span class="exam-pill {{ $participant->eligible ? 'is-ok' : 'is-warn' }}">{{ $participant->eligible ? 'Apto' : 'Com motivo' }}</span></div>@endforeach</div>
@endif
</section>

@if($context->canManage() && $exam->status === 'draft')
<section class="card exam-open" aria-labelledby="exam-abrir"><h2 id="exam-abrir">Abrir o exame</h2>
@if($problems)<p>Antes de abrir, resolva:</p><ul>@foreach($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
@else<p>Tudo certo. Ao abrir, a banca pode avaliar dentro do período. Participantes e conteúdo ainda podem ser ajustados.</p>@endif
<form method="post" action="{{ $context->route('open', ['exam' => $exam]) }}">@csrf<button class="button button-primary" @disabled($problems !== [])>Abrir exame</button></form>
</section>
@endif
@if($context->canManage() && $exam->isEditable())
<p class="exam-cancel"><a href="#exame-cancelar" data-modal-open="exame-cancelar">Cancelar este exame</a></p>
<x-modal id="exame-cancelar" title="Cancelar exame" :open="$errors->has('reason')">
<form class="form-stack" method="post" action="{{ $context->route('cancel', ['exam' => $exam]) }}">@csrf
<p>O exame sai da lista de abertos e rascunhos, mas continua guardado com o motivo.</p>
<div class="field"><label for="cancel_reason">Motivo</label><textarea id="cancel_reason" name="reason" required minlength="5" maxlength="500">{{ old('reason') }}</textarea>@error('reason')<p class="field-error">{{ $message }}</p>@enderror</div>
<button class="button button-danger">Cancelar exame</button></form>
</x-modal>
@endif
@endsection
