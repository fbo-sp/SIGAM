{{-- Quem deu a aula, gravado ao finalizar; aulas antigas mostram o professor previsto. --}}
@php($staffRows = $session->staff()->with('teacher.person:id,full_name,social_name')->get())
@php($staffName = fn ($teacher) => $teacher?->person ? ($teacher->person->social_name ?: $teacher->person->full_name) : null)
@php($staffLead = $staffRows->firstWhere('role', 'lead'))
@php($staffAssistants = $staffRows->where('role', 'assistant')->map(fn ($row) => $staffName($row->teacher))->filter()->sort()->values())
@if($staffLead)
<p class="staff-summary"><strong>Professor da aula:</strong> {{ $staffName($staffLead->teacher) }}@if($staffAssistants->isNotEmpty()) · <strong>Auxiliares:</strong> {{ $staffAssistants->join(', ') }}@endif</p>
@else
@php($plannedTeacher = \App\Models\TeacherProfile::query()->with('person:id,full_name,social_name')->find($session->planned_teacher_id ?: $session->classGroup->main_teacher_id))
@if($plannedTeacher)<p class="staff-summary"><strong>Professor previsto:</strong> {{ $staffName($plannedTeacher) }} <small>(quem deu a aula não foi registrado)</small></p>@endif
@endif
