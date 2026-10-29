{{-- Janela "Corrigir horário" de um horário atual. Espera: $schedule, $action, $weekday. --}}
@php($impact = app(\App\Services\Classes\ScheduleCorrectionService::class)->impact($schedule))
@php($failed = (int) old('schedule_id') === $schedule->id && $errors->any())
<x-modal :id="'corrigir-'.$schedule->id" :title="'Corrigir horário · '.$weekday" :open="$failed">
<p>Cadastrado hoje como <strong>{{ substr($schedule->start_time, 0, 5) }} às {{ substr($schedule->end_time, 0, 5) }}</strong>, desde {{ $schedule->valid_from->format('d/m/Y') }}.</p>
<form class="form-stack" method="post" action="{{ $action }}">@csrf
<input type="hidden" name="schedule_id" value="{{ $schedule->id }}">
@if($failed)<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="form-grid"><div class="field"><label for="fix_start_{{ $schedule->id }}">Início correto</label><input type="time" id="fix_start_{{ $schedule->id }}" name="start_time" value="{{ $failed ? old('start_time') : substr($schedule->start_time, 0, 5) }}" required></div><div class="field"><label for="fix_end_{{ $schedule->id }}">Fim correto</label><input type="time" id="fix_end_{{ $schedule->id }}" name="end_time" value="{{ $failed ? old('end_time') : substr($schedule->end_time, 0, 5) }}" required></div></div>
<div class="schedule-impact"><strong>Ao salvar, {{ $impact['total'] }} aula(s) deste horário passam para o horário correto:</strong><ul><li>{{ $impact['past'] }} aula(s) passada(s){{ $impact['closed'] ? ', '.$impact['closed'].' com chamada finalizada (as presenças continuam como estão)' : '' }};</li><li>{{ $impact['future'] }} próxima(s) aula(s){{ $impact['rescheduled'] ? ', incluindo '.$impact['rescheduled'].' reagendada(s) à parte' : '' }};</li><li>e as próximas que forem geradas.</li></ul>@if($impact['cancelled'])<p class="schedule-impact-keep">Não mudam: {{ $impact['cancelled'] }} aula(s) cancelada(s).</p>@endif</div>
<div class="field"><label for="fix_reason_{{ $schedule->id }}">Motivo da correção</label><input id="fix_reason_{{ $schedule->id }}" name="reason" value="{{ $failed ? old('reason') : '' }}" minlength="5" maxlength="500" placeholder="Ex.: horário cadastrado errado na criação da turma" required></div>
<button class="button button-primary">Corrigir {{ $impact['total'] }} aula(s)</button>
</form>
</x-modal>
