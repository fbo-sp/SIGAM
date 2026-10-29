{{-- Navegação do professor: abas no topo no computador e barra fixa embaixo no celular (Hoje, Turmas, Alunos e Mais). --}}
@php($tab = request()->routeIs('dashboard') ? 'hoje' : (request()->routeIs('teacher.classes', 'operation.classes*', 'operation.session*', 'operation.sessions') ? 'turmas' : (request()->routeIs('teacher.students', 'operation.students*', 'operation.progression.show', 'operation.progression.assess') ? 'alunos' : null)))
@php($workspace = app(\App\Services\Classes\TeacherWorkspaceService::class))
<nav class="teacher-tabbar" aria-label="Navegação do professor">
<a href="{{ route('dashboard') }}" @if($tab === 'hoje') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'inicio'])<span>Hoje</span></a>
<a href="{{ route('teacher.classes') }}" @if($tab === 'turmas') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'turmas'])<span>Turmas</span></a>
<a href="{{ route('teacher.students') }}" @if($tab === 'alunos') aria-current="page" @endif>@include('portal.partials.icon', ['name' => 'dados'])<span>Alunos</span></a>
<a href="#professor-mais" data-modal-open="professor-mais">@include('portal.partials.icon', ['name' => 'mais'])<span>Mais</span></a>
</nav>
<x-modal id="professor-mais" title="Mais opções">
<nav class="portal-shortcuts" aria-label="Mais opções do professor">
@foreach($workspace->dojos(auth()->user()) as $dojo)
@if(app(\App\Services\Access\OperationalScope::class)->canTeacher(auth()->user(), $dojo, 'progression.report') || app(\App\Services\Access\OperationalScope::class)->canDojo(auth()->user(), $dojo, 'progression.report'))<a class="card portal-shortcut" href="{{ route('operation.progression.panel', $dojo) }}">@include('portal.partials.icon', ['name' => 'progresso'])<span>Acompanhamento técnico{{ $workspace->dojos(auth()->user())->count() > 1 ? ' · '.$dojo->name : '' }}</span></a>@endif
@if(app(\App\Services\Access\OperationalScope::class)->canTeacher(auth()->user(), $dojo, 'announcement.send') || app(\App\Services\Access\OperationalScope::class)->canDojo(auth()->user(), $dojo, 'announcement.send'))<a class="card portal-shortcut" href="{{ route('operation.announcements.create', $dojo) }}">@include('portal.partials.icon', ['name' => 'avisos'])<span>Enviar aviso{{ $workspace->dojos(auth()->user())->count() > 1 ? ' · '.$dojo->name : '' }}</span></a>@endif
@endforeach
@if($workspace->dojos(auth()->user())->contains(fn ($dojo) => app(\App\Services\Access\OperationalScope::class)->canTeacher(auth()->user(), $dojo, 'report.export')))<a class="card portal-shortcut" href="{{ route('teacher.reports.index') }}">@include('portal.partials.icon', ['name' => 'documentos'])<span>Relatórios</span></a>@endif
<a class="card portal-shortcut" href="{{ route('teacher.study') }}">@include('portal.partials.icon', ['name' => 'estudar'])<span>Estudar</span></a>
<a class="card portal-shortcut" href="{{ route('operation.index') }}">@include('portal.partials.icon', ['name' => 'historico'])<span>Meus dojos</span></a>
<a class="card portal-shortcut" href="{{ route('notifications.index') }}">@include('portal.partials.icon', ['name' => 'avisos'])<span>Avisos</span></a>
<a class="card portal-shortcut" href="{{ route('account.show') }}">@include('portal.partials.icon', ['name' => 'documentos'])<span>Minha conta e senha</span></a>
@if(auth()->user()->hasPermission('portal.access', auth()->user()->association_id))<a class="card portal-shortcut" href="{{ route('portal.index') }}">@include('portal.partials.icon', ['name' => 'dados'])<span>Meu portal</span></a>@endif
</nav>
@php($openChampionships = \App\Models\Championship::query()->where('association_id', auth()->user()->association_id)->where('status', 'open')->where('registration_close_at', '>=', now())->orderBy('start_date')->limit(5)->get())
@if($openChampionships->isNotEmpty())<h3 class="modal-subtitle">Campeonatos com inscrição aberta</h3><ul class="plain-list">@foreach($openChampionships as $championship)<li>{{ $championship->name }} · {{ $championship->start_date->format('d/m/Y') }}</li>@endforeach</ul>@endif
</x-modal>
