{{-- Ícones simples em traço para os atalhos do portal. --}}
@php($paths = [
    'inicio' => 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
    'turmas' => 'M4 5h16v14H4zM4 9h16M9 5v14',
    'frequencia' => 'M5 4h14v16H5zM8 9l2 2 4-4M8 15h8',
    'financeiro' => 'M3 6h18v12H3zM3 10h18M7 15h4',
    'progresso' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    'campeonatos' => 'M8 4h8v5a4 4 0 0 1-8 0zM8 6H4a3 3 0 0 0 4 4M16 6h4a3 3 0 0 1-4 4M12 13v4M8 20h8',
    'historico' => 'M12 7v5l3 2M3 12a9 9 0 1 0 3-6.7M3 4v4h4',
    'documentos' => 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    'dados' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
    'avisos' => 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
    'mais' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
])
<svg class="portal-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['mais'] }}"/></svg>
