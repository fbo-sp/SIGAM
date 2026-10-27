{{-- Resumo do alvo de um aviso: "Turmas (2)", "Todo o dojo"... --}}
@php($counts = collect(['dojo_ids', 'class_ids', 'student_ids', 'teacher_ids'])->sum(fn ($key) => count($announcement->target[$key] ?? [])))
{{ match ($announcement->target_type) { 'all' => $announcement->dojo_id ? 'Todo o dojo' : 'Toda a associação', 'dojos' => 'Dojos', 'classes' => 'Turmas', default => 'Pessoas' } }}@if($announcement->target_type !== 'all') ({{ $counts }})@endif
