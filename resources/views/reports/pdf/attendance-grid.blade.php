@extends('reports.pdf.layout', ['title' => 'Grade de presença do mês', 'subtitle' => ucfirst($monthLabel).' · marque P (presente) ou F (falta) em cada aula'])
@section('body')
<table class="meta"><tr><td><b>Dojo</b>{{ $group->dojo->name }}</td><td><b>Turma</b>{{ $group->name }}</td><td><b>Horários</b>{{ $slots }}</td><td><b>Professor</b>{{ $teacher ?? '—' }}</td></tr></table>
<table class="grid"><thead><tr><th class="name" style="width:170px">Aluno</th>@foreach($dates as $date)<th>{{ $date }}</th>@endforeach<th style="width:34px">Total</th></tr></thead>
@foreach($rows as $row)<tr><td class="name">{{ $row['name'] }}@if($row['rank'])<br><small>{{ $row['rank'] }}</small>@endif</td>@foreach($dates as $date)<td></td>@endforeach<td></td></tr>@endforeach
@for($extra = 1; $extra <= 2; $extra++)<tr class="blank"><td class="name">Visitante: ____________________</td>@foreach($dates as $date)<td></td>@endforeach<td></td></tr>@endfor
<tr><td class="name"><b>Professor da aula (rubrica)</b></td>@foreach($dates as $date)<td></td>@endforeach<td></td></tr>
</table>
<table class="sign"><tr><td><div>Assinatura do professor</div></td><td><div>Registrado no JUDOMA em ___/___/______</div></td></tr></table>
@endsection
