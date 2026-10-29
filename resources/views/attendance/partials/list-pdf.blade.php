{{-- Lista de presença em PDF: finalizada quando a chamada está fechada; senão em branco, para marcar a caneta. --}}
@if($listUrl && !in_array($session->status, ['cancelled', 'rescheduled'], true))
<p class="session-pdf"><a class="button button-secondary" href="{{ $listUrl }}">{{ $session->attendance_state === 'closed' ? 'Baixar lista de presença (PDF)' : 'Imprimir lista em branco (PDF)' }}</a></p>
@endif
