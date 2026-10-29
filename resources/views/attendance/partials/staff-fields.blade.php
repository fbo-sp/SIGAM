{{-- Quem deu a aula e auxiliares presentes (vai para a frequência detalhada); sugestão: quem está fazendo a chamada, se for professor. --}}
@php($staffService = app(\App\Services\Attendance\SessionStaffService::class))
@php($staffOptions = $staffService->options($session))
@php($staffDefaults = $staffService->defaults($session, auth()->user()))
@if($staffOptions->isNotEmpty())
<fieldset class="card session-staff"><legend>Quem deu esta aula?</legend>
<div class="field"><label for="staff_lead">Professor da aula</label><select id="staff_lead" name="staff_lead">@foreach($staffOptions as $teacher)<option value="{{ $teacher->id }}" @selected((int) old('staff_lead', $staffDefaults['lead']) === $teacher->id)>{{ $teacher->person->social_name ?: $teacher->person->full_name }}{{ $teacher->id === $session->classGroup->main_teacher_id ? ' (principal da turma)' : '' }}</option>@endforeach</select></div>
@if($staffOptions->count() > 1)<div class="field"><span class="field-label">Auxiliares presentes</span><div class="staff-assistants">@foreach($staffOptions as $teacher)<label class="check-pill"><input type="checkbox" name="staff_assistants[]" value="{{ $teacher->id }}" @checked(in_array($teacher->id, array_map('intval', (array) old('staff_assistants', $staffDefaults['assistants'])), true))> {{ $teacher->person->social_name ?: $teacher->person->full_name }}</label>@endforeach</div><p class="form-help">Quem estiver como professor da aula não conta como auxiliar.</p></div>@endif
</fieldset>
@endif
