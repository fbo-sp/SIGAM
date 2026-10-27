@extends('layouts.app')
@section('title', 'Revisar aviso — JUDOMA')
@php($roles = \App\Services\Notifications\AnnouncementService::ROLES)
@php($emails = ($data['send_email'] ?? false) ? $audience->pluck('email')->filter()->map(fn ($email) => mb_strtolower(trim($email)))->unique()->count() : 0)
@section('content')
@include('announcements.partials.crumbs', ['current' => 'Revisar'])
<section class="page-heading"><div><span class="eyebrow">Revisar antes de enviar</span><h1>Vai para {{ $audience->count() }} pessoa(s)</h1><p>{{ collect($roles)->map(fn ($label, $key) => [$audience->where('role', $key)->count(), mb_strtolower($label)])->filter(fn ($row) => $row[0] > 0)->map(fn ($row) => $row[0].' '.$row[1].'(s)')->join(' · ') }}</p></div></section>
@if($audience->isEmpty())<div class="alert alert-warning" role="status">Ninguém recebe este aviso. Volte e revise a escolha.</div>@endif
<section class="portal-indicators announcement-stats"><article class="card portal-indicator"><span>Caixa do JUDOMA</span><strong>{{ $audience->whereNotNull('user')->count() }}</strong><small>com conta</small></article><article class="card portal-indicator"><span>E-mail</span><strong>{{ $emails }}</strong><small>{{ ($data['send_email'] ?? false) ? 'endereço(s) diferentes' : 'envio por e-mail desligado' }}</small></article><article class="card portal-indicator {{ $audience->whereNull('user')->whereNull('email')->count() ? 'is-warning' : '' }}"><span>Sem contato</span><strong>{{ $audience->whereNull('user')->whereNull('email')->count() }}</strong><small>sem conta e sem e-mail</small></article></section>
<article class="card announcement-message"><h2>{{ $data['title'] }}</h2><p class="announcement-body">{{ $data['body'] }}</p><small>{{ $signature }}</small></article>
<details class="card disclosure section-block"><summary>Ver destinatários</summary><ul class="plain-list announcement-recipients">@foreach($audience->take(300) as $row)<li>{{ $row['person']->social_name ?: $row['person']->full_name }} <small>{{ $roles[$row['role']] }}@if(! $row['user'] && ! $row['email']) · sem contato @elseif(! $row['user']) · só e-mail @endif</small></li>@endforeach @if($audience->count() > 300)<li><small>e mais {{ $audience->count() - 300 }}</small></li>@endif</ul></details>
<form class="form-actions announcement-actions" method="post" action="{{ route($prefix.'.store', $context) }}">
@csrf
<input type="hidden" name="token" value="{{ $token }}">
<input type="hidden" name="target_type" value="{{ $data['target_type'] }}">
@foreach(['dojo_ids', 'class_ids', 'student_ids', 'teacher_ids', 'include'] as $field)@foreach($data[$field] ?? [] as $item)<input type="hidden" name="{{ $field }}[]" value="{{ $item }}">@endforeach @endforeach
<input type="hidden" name="title" value="{{ $data['title'] }}">
<input type="hidden" name="body" value="{{ $data['body'] }}">
<input type="hidden" name="send_email" value="{{ ($data['send_email'] ?? false) ? 1 : 0 }}">
<button class="button button-secondary" type="submit" formaction="{{ route($prefix.'.create', $context) }}" formmethod="get" formnovalidate>Voltar e editar</button>
@if($audience->isNotEmpty())<button class="button button-primary" type="submit">Enviar aviso</button>@endif
</form>
@endsection
