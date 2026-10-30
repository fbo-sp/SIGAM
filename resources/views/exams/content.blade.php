@extends('layouts.app')
@section('title', 'Conteúdo · '.$exam->name.' — JUDOMA')
@section('content')
@php($rankName = $rank->name)
@php($canMove = $context->canManage() && $editable)
@include('exams.partials.crumbs', ['here' => 'Conteúdo'])
<div class="page-heading"><div><span class="eyebrow">{{ $context->canManage() ? 'Passo 3 · Conteúdo' : 'Banca · Conteúdo' }}</span><h1>Conteúdo por faixa</h1><p>Cada aluno é avaliado só no conteúdo da faixa que vai receber. @if(! $context->canManage()) Você pode desmarcar itens e justificar os que nenhuma aula abordou. @endif</p></div></div>
@include('exams.partials.steps', ['step' => 'conteudo'])
<nav class="section-tabs" aria-label="Faixas do exame">@foreach($ranks as $row)<a href="{{ $context->route('content', ['exam' => $exam, 'rank' => $row['rank']]) }}" @if($row['rank']->id === $rank->id) aria-current="page" @endif>{{ $row['rank']->display_name ?: $row['rank']->name }} · {{ $row['students'] }}</a>@endforeach</nav>
<p class="exam-hint">Sugerido: currículo da faixa {{ $rankName }}. Vem marcado o que é novo ou subiu de nível nesta faixa; o herdado de faixas anteriores vem desmarcado. Aulas = aulas com chamada finalizada {{ $exam->scope_type === 'association' || $exam->scope_type === 'student' ? 'da associação' : 'de '.$exam->scopeLabel() }} com alunos desta faixa presentes, desde a última graduação deles ({{ $content['sessions'] }} aula(s)).</p>
@if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $message)<span>{{ $message }}</span>@endforeach</div>@endif
@if($content['pending'] > 0 && ! $errors->any())<div class="alert alert-warning">{{ $content['pending'] }} item(ns) marcados sem nenhuma aula. Justifique para manter ou desmarque.</div>@endif
<form method="post" action="{{ $context->route('content.save', ['exam' => $exam, 'rank' => $rank]) }}">@csrf @method('put')
<div class="card list-card exam-content">
@foreach($content['groups'] as $group)
<div class="exam-group"><span>{{ $group['group']->name }} <small>· grupo de nota</small></span><small>média dos itens</small></div>
@forelse($group['items'] as $row)
@php($item = $row['item'])
@php($id = $item->id)
@if($item->kind === 'quiz')
<div class="exam-item {{ $item->selected ? '' : 'is-off' }}"><span class="exam-item-check" aria-hidden="true">✓</span><span class="exam-item-name"><strong>Quiz do exame</strong><small>{{ $item->selected ? $exam->quiz_question_count.' perguntas · nota automática (acertos ÷ perguntas × 10)' : 'Desligado nos dados do exame' }}</small>
@if($content['quiz'])@php($bank = $content['quiz'])<small class="{{ $bank['own'] < $bank['wanted'] ? 'text-warning' : '' }}">Banco da faixa {{ $rankName }}: {{ $bank['own'] }} pergunta(s) da faixa e {{ $bank['earlier'] }} de faixas anteriores. @if($bank['available'] < $bank['wanted']) Faltam {{ $bank['wanted'] - $bank['available'] }} para {{ $bank['wanted'] }}; cada aluno recebe as {{ $bank['available'] }} que existem. @elseif($bank['own'] < $bank['wanted']) O sorteio completa com faixas anteriores. @endif</small>@endif
@if($canMove)@include('exams.partials.group-select')@endif
</span>@if($content['quiz'])<span class="exam-cov {{ $content['quiz']['own'] >= $content['quiz']['wanted'] ? 'is-high' : 'is-mid' }}">{{ min($content['quiz']['own'], $content['quiz']['wanted']) }}/{{ $content['quiz']['wanted'] }}</span>@endif</div>
@else
@php($selected = (bool) old("items.$id.selected", $item->selected))
<div class="exam-item {{ $row['covered'] === 0 && $selected ? 'is-alert' : '' }}">
<input type="hidden" name="items[{{ $id }}][selected]" value="0">
<input type="checkbox" id="item-{{ $id }}" name="items[{{ $id }}][selected]" value="1" @checked($selected) @disabled(! $editable)>
<label class="exam-item-name" for="item-{{ $id }}"><strong>{{ $item->technique->name }}</strong><small>{{ \Illuminate\Support\Str::beforeLast($row['path'], ' > ') ?: $row['path'] }} · {{ ['new' => 'novo nesta faixa', 'raised' => 'subiu de nível nesta faixa', 'inherited' => 'herdado'][$item->origin] ?? 'currículo' }}</small></label>
<span class="exam-cov is-{{ $row['tone'] }}">{{ $row['covered'] }}/{{ $content['sessions'] }} aulas</span>
@if($row['covered'] === 0)<div class="exam-item-extra"><label class="sr-only" for="just-{{ $id }}">Justificativa para {{ $item->technique->name }}</label><input id="just-{{ $id }}" name="items[{{ $id }}][justification]" value="{{ old("items.$id.justification", $item->justification) }}" maxlength="500" placeholder="Nenhuma aula abordou. Justifique para manter" @disabled(! $editable)>@error("items.$id.justification")<p class="field-error">{{ $message }}</p>@enderror</div>@endif
@if($canMove)<div class="exam-item-extra">@include('exams.partials.group-select')</div>@endif
</div>
@endif
@empty
<div class="exam-item is-off"><span class="exam-item-name"><small>Grupo vazio. Mova itens para ele.</small></span></div>
@endforelse
@endforeach
@if($content['groups']->isEmpty())<div class="empty-state"><p>O currículo da faixa {{ $rankName }} está vazio. Preencha em Modalidades > Currículo e salve os participantes de novo.</p></div>@endif
</div>
@if($editable)<div class="form-actions"><button class="button button-primary">Salvar conteúdo desta faixa</button></div>@endif
</form>
@if($canMove)
<form class="card exam-new-group" method="post" action="{{ $context->route('groups.store', ['exam' => $exam, 'rank' => $rank]) }}">@csrf
<div class="field"><label for="group-name">Novo grupo de nota nesta faixa</label><input id="group-name" name="name" maxlength="80" placeholder="Ex.: Kata" value="{{ old('name') }}" required></div><button class="button button-secondary">Criar grupo</button>
</form>
@endif
@endsection
