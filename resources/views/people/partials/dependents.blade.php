{{-- Bloco Dependentes da edição de pessoas. Espera: $association, $person, $dependentLinks, $dependentCandidates (GuardianService). --}}
@php($relationships = \App\Services\Sports\GuardianService::RELATIONSHIPS)
@php($formKey = old('form_key'))
@php($firstName = \Illuminate\Support\Str::before($person->social_name ?: $person->full_name, ' '))
<section class="section-block guardians-block" id="dependentes">
<div class="section-heading section-heading-row"><div><h2>Dependentes</h2><p>Alunos pelos quais {{ $firstName }} responde.</p></div><a class="button button-primary" href="#dependente-novo" data-modal-open="dependente-novo">Adicionar dependente</a></div>
@if($dependentLinks->isEmpty())<div class="card empty-state"><p>Nenhum dependente vigente.</p></div>
@else<div class="card list-card guardian-list">@foreach($dependentLinks as $link) @php($dependent = $link->student)
<div class="guardian-row"><div class="guardian-head"><span><strong>{{ $dependent->person->social_name ?: $dependent->person->full_name }}</strong><small>{{ collect([$relationships[$link->relationship_type] ?? 'Outro', $dependent->registration_number, $dependent->primaryDojo?->name, 'desde '.$link->start_date->format('d/m/Y'), $dependent->status === 'pre_registration' ? 'pré-cadastro' : null])->filter()->implode(' · ') }}</small></span></div>
<div class="guardian-tags">@if($link->is_legal_guardian)<span class="guardian-tag is-on">Legal</span>@endif @if($link->is_financial_guardian)<span class="guardian-tag is-on">Financeiro</span>@endif @if($link->is_primary)<span class="guardian-tag is-on">Principal</span>@endif @if($link->can_receive_notifications)<span class="guardian-tag is-on">Recebe avisos</span>@endif</div>
<div class="guardian-actions"><a class="button button-secondary" href="#dependente-editar-{{ $link->id }}" data-modal-open="dependente-editar-{{ $link->id }}">Editar</a><a class="button button-secondary" href="#dependente-encerrar-{{ $link->id }}" data-modal-open="dependente-encerrar-{{ $link->id }}">Encerrar vínculo</a><a class="button button-secondary" href="{{ route('associations.students.show', [$association, $dependent]) }}">Ficha do aluno</a></div>
<x-modal :id="'dependente-editar-'.$link->id" :title="'Editar · '.$dependent->person->full_name" :open="$formKey === 'dep-edit-'.$link->id && $errors->any()">
<form class="form-stack" method="post" action="{{ route('associations.students.guardians.update', [$association, $dependent, $link]) }}">@csrf @method('PUT')<input type="hidden" name="form_key" value="dep-edit-{{ $link->id }}">
@if($formKey === 'dep-edit-'.$link->id && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="field"><label for="dependent_relationship_{{ $link->id }}">{{ $firstName }} é</label><select id="dependent_relationship_{{ $link->id }}" name="relationship_type">@foreach($relationships as $value => $label)<option value="{{ $value }}" @selected($link->relationship_type === $value)>{{ $label }}</option>@endforeach</select></div>
@include('sports.students.partials.guardian-flags', ['flags' => $link, 'portal' => false])
<button class="button button-primary">Salvar</button></form>
</x-modal>
<x-modal :id="'dependente-encerrar-'.$link->id" :title="'Encerrar vínculo · '.$dependent->person->full_name" :open="$formKey === 'dep-end-'.$link->id && $errors->any()">
<form class="form-stack" method="post" action="{{ route('associations.students.guardians.end', [$association, $dependent, $link]) }}">@csrf<input type="hidden" name="form_key" value="dep-end-{{ $link->id }}">
@if($formKey === 'dep-end-'.$link->id && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<p>{{ $person->full_name }} deixa de ser responsável por {{ $dependent->person->full_name }}: não recebe mais avisos nem vê o aluno no portal. O histórico continua guardado.</p>
<div class="field"><label for="dependent_end_{{ $link->id }}">Último dia do vínculo</label><input type="date" id="dependent_end_{{ $link->id }}" name="end_date" value="{{ today()->toDateString() }}" min="{{ $link->start_date->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
<div class="field"><label for="dependent_reason_{{ $link->id }}">Motivo (opcional)</label><input id="dependent_reason_{{ $link->id }}" name="reason" maxlength="500"></div>
<button class="button button-danger">Encerrar vínculo</button></form>
</x-modal>
</div>@endforeach</div>@endif
@if($person->user && $person->user->association_id === $association->id && $dependentLinks->isNotEmpty())<p class="guardian-hint">Com a conta de acesso, {{ $firstName }} vê {{ $dependentLinks->count() === 1 ? 'o dependente' : 'os '.$dependentLinks->count().' dependentes' }} no portal.</p>@endif
@php($failedAdd = $formKey === 'dep-add' && $errors->any())
<x-modal id="dependente-novo" :title="'Adicionar dependente · '.$person->full_name" :open="$failedAdd">
<form class="form-stack" method="post" action="{{ route('associations.people.dependents.store', [$association, $person]) }}">@csrf<input type="hidden" name="form_key" value="dep-add">
@if($failedAdd)<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@if($dependentCandidates->isEmpty())<p>Não há alunos ativos ou em pré-cadastro disponíveis para vincular.</p>
@else<div class="field"><label for="dependent-filter">Buscar aluno</label><input id="dependent-filter" type="search" placeholder="Nome ou matrícula" data-filter-list="dependent-list" autocomplete="off">
<div class="check-list" id="dependent-list">@foreach($dependentCandidates as $candidate)<label class="check-row"><input type="radio" name="student_id" value="{{ $candidate->id }}" @checked($failedAdd && (int) old('student_id') === $candidate->id) required><span>{{ $candidate->person->social_name ?: $candidate->person->full_name }}<small> · {{ collect([$candidate->registration_number, $candidate->primaryDojo?->name, $candidate->person->birth_date ? $candidate->person->birth_date->age.' anos' : null])->filter()->implode(' · ') }}</small></span></label>@endforeach</div></div>
<div class="form-grid"><div class="field"><label for="dependent_relationship">{{ $firstName }} é</label><select id="dependent_relationship" name="relationship_type">@foreach($relationships as $value => $label)<option value="{{ $value }}" @selected(($failedAdd ? old('relationship_type') : 'mother') === $value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="dependent_start">Desde</label><input type="date" id="dependent_start" name="start_date" value="{{ $failedAdd ? old('start_date') : today()->toDateString() }}" max="{{ today()->toDateString() }}" required></div></div>
@include('sports.students.partials.guardian-flags', ['flags' => $failedAdd ? null : ['can_receive_notifications' => true, 'is_legal_guardian' => true], 'portal' => false])
<p class="guardian-hint">Lista os alunos ativos e em pré-cadastro da associação que ainda não têm {{ $firstName }} como responsável.</p>
<button class="button button-primary">Vincular dependente</button>@endif</form>
</x-modal>
</section>
