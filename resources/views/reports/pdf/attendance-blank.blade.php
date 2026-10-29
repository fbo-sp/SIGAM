@extends('reports.pdf.layout', ['title' => 'Lista de presença para preencher', 'subtitle' => 'Marque um X em SIM ou NÃO e depois registre no JUDOMA'])
@section('body')
<table class="meta"><tr><td><b>Dojo</b>{{ $group->dojo->name }}</td><td><b>Turma</b>{{ $group->name }}</td><td><b>Aula</b>{{ $when }}</td><td><b>Professor previsto</b>{{ $planned ?? '—' }}</td></tr>
<tr><td><b>Professor da aula</b>&nbsp;</td><td><b>Auxiliares</b>&nbsp;</td><td><b>Alunos na lista</b>{{ $rows->count() }}</td><td><b>Técnicas da aula</b>&nbsp;</td></tr></table>
<table class="list"><tr><th style="width:24px">#</th><th>Aluno</th><th style="width:110px">Faixa</th><th style="width:40px">SIM</th><th style="width:40px">NÃO</th><th style="width:150px">Observação</th></tr>
@foreach($rows as $index => $row)<tr class="{{ $index % 2 ? 'alt' : '' }}"><td>{{ $index + 1 }}</td><td>{{ $row['name'] }}@if($row['visitor']) <small>(visitante)</small>@endif</td><td>{{ $row['rank'] ?? '—' }}</td><td><span class="box"></span></td><td><span class="box"></span></td><td></td></tr>@endforeach
@for($extra = 1; $extra <= 3; $extra++)<tr class="blank"><td>{{ $rows->count() + $extra }}</td><td>Visitante: ____________________________</td><td></td><td><span class="box"></span></td><td><span class="box"></span></td><td></td></tr>@endfor
</table>
<table class="sign"><tr><td><div>Assinatura do professor</div></td><td><div>Registrado no JUDOMA em ___/___/______</div></td></tr></table>
@endsection
