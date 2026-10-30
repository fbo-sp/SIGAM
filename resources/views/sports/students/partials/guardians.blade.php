{{-- Bloco Responsáveis (associação, dojo e professor). Espera: $student, $guardianLinks, $guardianCandidates, $guardianRoutes, $canManageGuardians (GuardianService::viewData). --}}
@php($relationships = \App\Services\Sports\GuardianService::RELATIONSHIPS)
@php($guardianRoute = fn (string $name, ?\App\Models\StudentGuardian $link = null) => route($guardianRoutes['prefix'].$name, [...$guardianRoutes['base'], ...($link ? [$link] : [])]))
@php($formKey = old('form_key'))
<section class="section-block guardians-block" id="responsaveis">
<div class="section-heading section-heading-row"><div><h2>Responsáveis</h2><p>Quem responde pelo aluno, recebe avisos e pode usar o portal.</p></div>@if($canManageGuardians)<a class="button button-primary" href="#responsavel-novo" data-modal-open="responsavel-novo">Adicionar responsável</a>@endif</div>
@if($canManageGuardians)<form class="card guardian-need" method="post" action="{{ $guardianRoute('requires') }}">@csrf<label><input type="checkbox" name="requires_guardian" value="1" @checked($student->requires_guardian)><span><strong>Precisa de responsável mesmo sendo maior de idade</strong><small>Para alunos com alguma condição que exija acompanhamento. Não registre o diagnóstico.</small></span></label><button class="button button-secondary">Salvar</button></form>
@elseif($student->requires_guardian)<p class="guardian-need-note">Este aluno precisa de responsável mesmo sendo maior de idade.</p>@endif
@if($guardianLinks->isEmpty())<div class="card empty-state"><p>Nenhum responsável vigente.@if($student->needsGuardian()) Este aluno precisa de um responsável legal.@endif</p></div>
@else<div class="card list-card guardian-list">@foreach($guardianLinks as $link) @php($person = $link->person) @php($account = $person->user && $person->user->association_id === $student->association_id ? $person->user : null)
<div class="guardian-row"><div class="guardian-head"><span><strong>{{ $person->full_name }} @if($guardianRoutes['prefix'] === 'associations.students.guardians.' && auth()->user()->hasPermission('person.manage', $student->association_id))<x-edit-link :href="route('associations.people.edit', [$student->association_id, $person, 'voltar' => request()->getRequestUri()])" :label="'Editar dados de '.$person->full_name" />@endif</strong><small>{{ $relationships[$link->relationship_type] ?? 'Outro' }} · desde {{ $link->start_date->format('d/m/Y') }} · {{ \App\Support\Normalization\InputNormalizer::phoneDisplay($person->mobile ?: $person->phone) ?? 'sem telefone' }}{{ $person->email ? '' : ' · sem e-mail' }}</small></span></div>
<div class="guardian-tags">@if($link->is_legal_guardian)<span class="guardian-tag is-on">Legal</span>@endif @if($link->is_financial_guardian)<span class="guardian-tag is-on">Financeiro</span>@endif @if($link->is_primary)<span class="guardian-tag is-on">Principal</span>@endif @if($link->can_receive_notifications)<span class="guardian-tag is-on">Recebe avisos</span>@endif
@if($account && $account->status === 'active')<span class="guardian-tag is-ok">Portal ativo</span>@elseif($account && $account->status === 'pending')<span class="guardian-tag is-warn">Convite enviado</span>@elseif($account)<span class="guardian-tag">Conta inativa</span>@elseif($person->user)<span class="guardian-tag">Conta em outra associação</span>@else<span class="guardian-tag is-warn">Sem acesso ao portal</span>@endif</div>
@if($canManageGuardians)<div class="guardian-actions"><a class="button button-secondary" href="#responsavel-editar-{{ $link->id }}" data-modal-open="responsavel-editar-{{ $link->id }}">Editar</a><a class="button button-secondary" href="#responsavel-encerrar-{{ $link->id }}" data-modal-open="responsavel-encerrar-{{ $link->id }}">Encerrar vínculo</a>@if(! $person->user)<a class="button button-secondary" href="#responsavel-portal-{{ $link->id }}" data-modal-open="responsavel-portal-{{ $link->id }}">Liberar portal</a>@endif</div>
<x-modal :id="'responsavel-editar-'.$link->id" :title="'Editar · '.$person->full_name" :open="$formKey === 'edit-'.$link->id && $errors->any()">
<form class="form-stack" method="post" action="{{ $guardianRoute('update', $link) }}">@csrf @method('PUT')<input type="hidden" name="form_key" value="edit-{{ $link->id }}">
@if($formKey === 'edit-'.$link->id && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="field"><label for="guardian_relationship_{{ $link->id }}">Parentesco</label><select id="guardian_relationship_{{ $link->id }}" name="relationship_type">@foreach($relationships as $value => $label)<option value="{{ $value }}" @selected($link->relationship_type === $value)>{{ $label }}</option>@endforeach</select></div>
@include('sports.students.partials.guardian-flags', ['flags' => $link, 'portal' => false])
<button class="button button-primary">Salvar</button></form>
<p class="guardian-hint">Nome, CPF e contatos da pessoa são corrigidos no cadastro de pessoas, pela gestão.</p>
</x-modal>
<x-modal :id="'responsavel-encerrar-'.$link->id" :title="'Encerrar vínculo · '.$person->full_name" :open="$formKey === 'end-'.$link->id && $errors->any()">
<form class="form-stack" method="post" action="{{ $guardianRoute('end', $link) }}">@csrf<input type="hidden" name="form_key" value="end-{{ $link->id }}">
@if($formKey === 'end-'.$link->id && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<p>{{ $person->full_name }} deixa de ser responsável por {{ $student->person->full_name }}: não recebe mais avisos nem vê o aluno no portal. O histórico continua guardado.</p>
<div class="field"><label for="guardian_end_{{ $link->id }}">Último dia do vínculo</label><input type="date" id="guardian_end_{{ $link->id }}" name="end_date" value="{{ today()->toDateString() }}" min="{{ $link->start_date->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
<div class="field"><label for="guardian_reason_{{ $link->id }}">Motivo (opcional)</label><input id="guardian_reason_{{ $link->id }}" name="reason" maxlength="500"></div>
<button class="button button-danger">Encerrar vínculo</button></form>
</x-modal>
@if(! $person->user)<x-modal :id="'responsavel-portal-'.$link->id" :title="'Liberar portal · '.$person->full_name" :open="$formKey === 'portal-'.$link->id && $errors->any()">
<form class="form-stack" method="post" action="{{ $guardianRoute('portal', $link) }}">@csrf<input type="hidden" name="form_key" value="portal-{{ $link->id }}">
@if($formKey === 'portal-'.$link->id && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<p>O JUDOMA envia um convite para {{ $person->full_name }} criar a senha. No portal, a pessoa vê os dados do aluno e, se for responsável legal, corrige contatos, emergência e endereço.</p>
<div class="field"><label for="guardian_portal_email_{{ $link->id }}">E-mail</label><input type="email" id="guardian_portal_email_{{ $link->id }}" name="email" value="{{ $formKey === 'portal-'.$link->id ? old('email') : $person->email }}" required></div>
<button class="button button-primary">Enviar convite</button></form>
</x-modal>@endif
@endif</div>@endforeach</div>@endif
@if($canManageGuardians) @php($failedAdd = $formKey === 'add' && $errors->any())
<x-modal id="responsavel-novo" :title="'Adicionar responsável · '.($student->person->social_name ?: $student->person->full_name)" :open="$failedAdd">
<form class="form-stack" method="post" action="{{ $guardianRoute('store') }}">@csrf<input type="hidden" name="form_key" value="add">
@if($failedAdd)<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@php($mode = $failedAdd ? old('mode', 'new') : 'new')
<fieldset class="guardian-mode"><legend class="sr-only">Quem é o responsável</legend><label><input type="radio" name="mode" value="new" @checked($mode === 'new')><span>Pessoa nova</span></label><label><input type="radio" name="mode" value="existing" @checked($mode === 'existing') @disabled($guardianCandidates->isEmpty())><span>Já cadastrada</span></label></fieldset>
<div class="form-stack guardian-fields" data-show-when="mode:new">
<div class="field"><label for="guardian_full_name">Nome completo</label><input id="guardian_full_name" name="full_name" value="{{ $failedAdd ? old('full_name') : '' }}" maxlength="255" required></div>
<div class="form-grid"><div class="field"><label for="guardian_cpf">CPF</label><input id="guardian_cpf" name="cpf" inputmode="numeric" value="{{ $failedAdd ? old('cpf') : '' }}" placeholder="opcional"></div><div class="field"><label for="guardian_mobile">Celular</label><input id="guardian_mobile" name="mobile" inputmode="tel" value="{{ $failedAdd ? old('mobile') : '' }}"></div></div>
<div class="field"><label for="guardian_email">E-mail</label><input type="email" id="guardian_email" name="email" value="{{ $failedAdd ? old('email') : '' }}" placeholder="para liberar o portal"></div>
</div>
@if($guardianCandidates->isNotEmpty())<div class="field" data-show-when="mode:existing"><label for="guardian_person_id">Pessoa</label><select id="guardian_person_id" name="guardian_person_id" required><option value="">Selecione</option>@foreach($guardianCandidates as $candidate)<option value="{{ $candidate->id }}" @selected($failedAdd && (int) old('guardian_person_id') === $candidate->id)>{{ $candidate->full_name }}{{ $candidate->mobile ? ' · '.\App\Support\Normalization\InputNormalizer::phoneDisplay($candidate->mobile) : '' }}</option>@endforeach</select>@if($guardianRoutes['prefix'] === 'operation.students.guardians.')<p class="guardian-hint">Aparecem os responsáveis dos outros alunos que você acompanha, como irmãos.</p>@endif</div>@endif
<div class="form-grid"><div class="field"><label for="guardian_relationship">Parentesco</label><select id="guardian_relationship" name="relationship_type">@foreach($relationships as $value => $label)<option value="{{ $value }}" @selected(($failedAdd ? old('relationship_type') : 'mother') === $value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="guardian_start">Desde</label><input type="date" id="guardian_start" name="start_date" value="{{ $failedAdd ? old('start_date') : today()->toDateString() }}" max="{{ today()->toDateString() }}" required></div></div>
@include('sports.students.partials.guardian-flags', ['flags' => $failedAdd ? null : ['can_receive_notifications' => true, 'is_legal_guardian' => $guardianLinks->isEmpty(), 'is_primary' => $guardianLinks->isEmpty()], 'portal' => true])
<p class="guardian-hint">Se o CPF, ou o nome com o mesmo celular, já existir na associação, o JUDOMA usa o cadastro existente em vez de criar outro.</p>
<button class="button button-primary">Vincular responsável</button></form>
</x-modal>@endif
</section>
