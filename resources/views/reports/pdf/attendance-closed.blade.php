@extends('reports.pdf.layout', ['title' => 'Lista de presença', 'subtitle' => 'Chamada finalizada'])
@section('body')
<table class="meta"><tr><td><b>Dojo</b>{{ $group->dojo->name }}</td><td><b>Turma</b>{{ $group->name }}</td><td><b>Aula</b>{{ $when }}</td><td><b>Professor da aula</b>{{ $lead ?? '—' }}</td></tr>
<tr><td><b>Auxiliares</b>{{ $assistants ?: '—' }}</td><td><b>Finalizada em</b>{{ $closedBy ?? '—' }}</td><td><b>Técnicas da aula</b>{{ $techniques ?: '—' }}</td><td><b>Visitantes</b>{{ $rows->where('visitor', true)->count() }}</td></tr></table>
<table class="list"><tr><th style="width:24px">#</th><th>Aluno</th><th style="width:130px">Faixa</th><th style="width:70px">Presença</th></tr>
@foreach($rows as $index => $row)<tr class="{{ $index % 2 ? 'alt' : '' }}"><td>{{ $index + 1 }}</td><td>{{ $row['name'] }}@if($row['visitor']) <small>(visitante)</small>@endif</td><td>{{ $row['rank'] ?? '—' }}</td>@if($row['present'] === null)<td class="muted">Sem marcação</td>@else<td class="{{ $row['present'] ? 'sim' : 'nao' }}">{{ $row['present'] ? 'SIM' : 'NÃO' }}</td>@endif</tr>@endforeach
</table>
<p class="tot"><span>Presentes: {{ $rows->where('present', true)->count() }}</span><span>Ausentes: {{ $rows->where('present', false)->count() }}</span><span>Total na lista: {{ $rows->count() }}</span></p>
@endsection
