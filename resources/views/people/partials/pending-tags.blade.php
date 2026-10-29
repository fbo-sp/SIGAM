{{-- Etiquetas das pendências de cadastro ($items de PendingDataService); vermelhas as de alta gravidade. --}}
<span class="pending-tags">@foreach($items as $item)<span class="pending-tag {{ \App\Services\People\PendingDataService::ITEMS[$item][2] ? 'is-high' : '' }}">{{ \App\Services\People\PendingDataService::ITEMS[$item][0] }}</span>@endforeach</span>
