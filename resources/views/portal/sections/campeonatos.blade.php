@extends('layouts.app')
@php($registrationNames = ['pending' => 'Em análise', 'approved' => 'Aprovada', 'rejected' => 'Recusada', 'cancelled' => 'Cancelada'])
@section('title', $title.' — JUDOMA')
@section('content')
<div class="portal-page">
@include('portal.partials.header', ['subtitle' => 'Acompanhe inscrições e participe quando a janela estiver aberta.'])
    <div class="portal-list">@foreach($student->championshipRegistrations as $registration)<article class="card portal-row"><div><span class="status-pill">{{ $registrationNames[$registration->status] ?? $registration->status }}</span><h3>{{ $registration->championship->name }}</h3><p>{{ $registration->championship->start_date->format('d/m/Y') }} · {{ $registration->category }}</p></div>@if($registration->result)<strong>{{ $registration->result->placement ? $registration->result->placement.'º lugar' : 'Participação registrada' }}</strong>@endif</article>@endforeach</div>
    @foreach($championships as $championship)
        @unless($student->championshipRegistrations->contains('championship_id', $championship->id))
        <details class="card disclosure portal-registration"><summary>Inscrever em {{ $championship->name }} — {{ $championship->start_date->format('d/m/Y') }}</summary>
            <form class="form-grid" method="post" action="{{ route('portal.students.championships.register', [$student, $championship]) }}">@csrf
                <div class="field"><label>Categoria</label><input name="category" required maxlength="120" value="{{ old('category') }}"></div>
                <div class="field"><label>Peso (kg)</label><input name="weight" required inputmode="decimal" value="{{ old('weight') }}"></div>
                <div class="field"><label>Graduação</label><select name="rank_id" required><option value="">Selecione</option>@foreach($student->martialArts->where('martial_art_id', $championship->martial_art_id)->flatMap->rankHistory->unique('rank_id') as $history)<option value="{{ $history->rank_id }}">{{ $history->rank->display_name }}</option>@endforeach</select></div>
                <div class="field"><label>Filiação (opcional)</label><select name="federation_membership_id"><option value="">Sem filiação</option>@foreach($student->federationMemberships->where('martial_art_id', $championship->martial_art_id)->where('status', 'active') as $membership)<option value="{{ $membership->id }}">{{ $membership->federation->name }} — {{ $membership->registration_number }}</option>@endforeach</select></div>
                <div class="field"><label>Equipe (opcional)</label><input name="team_name" maxlength="180"></div><div class="field"><label>Observação (opcional)</label><input name="notes" maxlength="1000"></div>
                <div class="field field-wide"><button class="button button-primary" type="submit">Enviar inscrição</button></div>
            </form>
        </details>
        @endunless
    @endforeach
@if($student->championshipRegistrations->isEmpty() && $championships->isEmpty())<div class="card empty-state"><p>Nenhum campeonato com inscrição aberta no momento.</p></div>@endif
@include('portal.partials.tabbar', ['current' => 'mais'])
</div>
@endsection
