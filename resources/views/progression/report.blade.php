@extends('layouts.app')
@section('title', 'Acompanhamento técnico — '.$association->trade_name)
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.show', $association) }}">{{ $association->trade_name }}</a><span>/</span><span>Acompanhamento técnico</span></nav>
<div class="page-heading"><div><span class="eyebrow">Painel da associação</span><h1>Acompanhamento técnico</h1><p>Comparação entre dojos e professores: itens que faltam por aluno e registro das técnicas nas aulas.</p></div></div>

@if($martialArts->isEmpty())
<div class="card empty-state"><h2>Nenhuma turma ativa</h2><p>O painel compara as turmas ativas da associação.</p></div>
@else
<form class="card filter-form" method="get" action="{{ route('associations.progression.report', $association) }}">
<div class="field"><label for="modalidade">Modalidade</label><select id="modalidade" name="modalidade">@foreach($martialArts as $option)<option value="{{ $option->id }}" @selected($option->id === $martialArt->id)>{{ $option->name }}</option>@endforeach</select></div>
<div class="field"><label for="de">De</label><input id="de" name="de" type="date" value="{{ $from->toDateString() }}"></div>
<div class="field"><label for="ate">Até</label><input id="ate" name="ate" type="date" value="{{ $until->toDateString() }}">@error('ate')<p class="field-error">{{ $message }}</p>@enderror</div>
<button class="button button-secondary">Filtrar</button></form>

@foreach(['dojos' => 'Por dojo', 'teachers' => 'Por professor principal'] as $key => $title)
<section class="section-block"><div class="section-heading"><h2>{{ $title }}</h2><p>Média de itens abaixo do exigido por aluno, separada em teórico e prático.</p></div>
<div class="card list-card">@forelse($comparison[$key] as $row)<div class="list-row progress-row"><span><strong>{{ $row['label'] }}</strong><small>{{ $row['classes'] }} turma(s) · {{ $row['students'] }} aluno(s) · {{ $row['ready'] }} pronto(s) no currículo · técnicas registradas em {{ $row['sessions_with_techniques'] }} de {{ $row['sessions'] }} aula(s)@if(isset($row['assessments'])) · {{ $row['assessments'] }} avaliação(ões) no período @endif</small></span><span class="level-gap"><span class="status-pill">Teórico: {{ number_format($row['missing_theoretical'], 1, ',', '') }}</span><span class="status-pill">Prático: {{ number_format($row['missing_practical'], 1, ',', '') }}</span></span></div>@empty<div class="empty-state"><p>Nada para comparar.</p></div>@endforelse</div>
</section>
@endforeach
@endif
@endsection
