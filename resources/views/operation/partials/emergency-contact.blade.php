{{-- Contato de emergência do aluno na turma ($student com person e guardians vigentes carregados). Sem dados financeiros. --}}
@php($person = $student->person)
@php($tel = fn (?string $phone) => $phone ? preg_replace('/[^0-9+]/', '', $phone) : null)
<x-modal :id="'emergencia-'.$student->id" :title="'Emergência · '.($person->social_name ?: $person->full_name)">
<dl class="emergency-list">
<div><dt>Contato de emergência</dt><dd>@if($person->emergency_contact_name || $person->emergency_contact_phone){{ $person->emergency_contact_name ?: 'Sem nome informado' }}@if($person->emergency_contact_phone) · <a href="tel:{{ $tel($person->emergency_contact_phone) }}">{{ $person->emergency_contact_phone }}</a>@endif @else Não informado. Peça ao gestor do dojo para completar o cadastro. @endif</dd></div>
@foreach($student->guardians as $guardian)@php($phone = $guardian->person?->mobile ?: $guardian->person?->phone)<div><dt>{{ $guardian->is_legal_guardian ? 'Responsável legal' : 'Responsável' }}</dt><dd>{{ $guardian->person?->social_name ?: $guardian->person?->full_name }}@if($phone) · <a href="tel:{{ $tel($phone) }}">{{ $phone }}</a>@endif</dd></div>@endforeach
@if($person->mobile || $person->phone)<div><dt>Telefone do aluno</dt><dd><a href="tel:{{ $tel($person->mobile ?: $person->phone) }}">{{ $person->mobile ?: $person->phone }}</a></dd></div>@endif
@if($person->birth_date)<div><dt>Idade</dt><dd>{{ $person->birth_date->age }} anos{{ $person->birth_date->age < 18 ? ' · menor de idade' : '' }}</dd></div>@endif
</dl>
</x-modal>
