@extends('layouts.app')
@section('title', 'Técnicas — '.$martialArt->name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('content')
@include('progression._tabs', ['current' => 'catalog', 'title' => 'Catálogo de técnicas', 'subtitle' => 'Grupos e itens que podem ser cobrados no currículo das graduações.'])

@if($canManage && $templates !== [])
<details class="card disclosure" @if($isEmpty) open @endif><summary>{{ $isEmpty ? 'Começar com um modelo pronto' : 'Completar com o modelo' }}</summary><form class="form-grid" method="post" action="{{ route('associations.techniques.template', [$association, $martialArt]) }}">@csrf
<div class="field field-wide"><label for="template_key">Modelo</label><select id="template_key" name="template_key" required>@foreach($templates as $key => $template)<option value="{{ $key }}">{{ $template['label'] }}</option>@endforeach</select>@error('template_key')<p class="field-error">{{ $message }}</p>@enderror</div>
@foreach($templates as $template)<div class="alert alert-info field-wide"><strong>{{ $template['label'] }}</strong><span>{{ $template['description'] }}</span><small>{{ $template['review_notice'] }}</small></div>@endforeach
<p class="field-wide">O modelo copia o catálogo e as exigências de cada graduação para a associação. Depois tudo pode ser ajustado. Aplicar de novo inclui o que falta, sem desfazer seus ajustes.</p>
@unless($isEmpty)<label class="check-row field-wide"><input type="checkbox" name="update_curriculum" value="1"> <span>Atualizar também o currículo que veio do modelo<br><small>As exigências que a associação nunca ajustou passam a seguir a versão atual do modelo: mudam de nível ou saem. O que foi ajustado na tela de currículo fica como está, e cada troca fica na auditoria.</small></span></label>@endunless
<div class="form-actions field-wide"><button class="button button-primary">Aplicar modelo</button></div></form></details>
@endif

@if($canManage)
<details class="card disclosure" id="incluir" @if($within || $errors->hasAny(['name','parent_id','kind','knowledge_type','video_url'])) open @endif><summary>Incluir grupo ou item</summary><form class="form-grid" method="post" action="{{ route('associations.techniques.store', [$association, $martialArt]) }}">@csrf
<div class="field field-wide"><label for="parent_id">Dentro de</label><select id="parent_id" name="parent_id"><option value="">Nenhum (novo grupo principal)</option>@foreach($groups as $id => $path)<option value="{{ $id }}" @selected((int) old('parent_id', $within) === $id)>{{ $path }}</option>@endforeach</select>@error('parent_id')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="kind">Tipo de nó</label><select id="kind" name="kind"><option value="item" @selected(old('kind') === 'item')>Técnica ou item avaliado</option><option value="group" @selected(old('kind') === 'group')>Grupo</option></select>@error('kind')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="knowledge_type">Conhecimento</label><select id="knowledge_type" name="knowledge_type">@foreach($knowledgeLabels as $value => $label)<option value="{{ $value }}" @selected(old('knowledge_type') === $value)>{{ $label }}</option>@endforeach</select><small>Só vale para grupo principal. Dentro de um grupo, o item segue o tipo do grupo.</small>@error('knowledge_type')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="name">Nome</label><input id="name" name="name" value="{{ old('name') }}" maxlength="160" required>@error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="original_name">Nome original</label><input id="original_name" name="original_name" value="{{ old('original_name') }}" maxlength="160"></div>
<div class="field field-wide"><label for="description">Descrição para estudo</label><textarea id="description" name="description" rows="2" maxlength="2000">{{ old('description') }}</textarea></div>
<div class="field"><label for="video_url">Link de vídeo</label><input id="video_url" name="video_url" type="url" value="{{ old('video_url') }}" placeholder="https://">@error('video_url')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="sort_order">Ordem</label><input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order') }}"></div>
<div class="form-actions field-wide"><button class="button button-primary">Incluir no catálogo</button></div></form></details>
@endif

@if($isEmpty)
<div class="card empty-state"><h2>Catálogo vazio</h2><p>{{ $canManage ? 'Aplique o modelo pronto ou inclua o primeiro grupo.' : 'A associação ainda não cadastrou técnicas para esta modalidade.' }}</p></div>
@endif

@if(! $isEmpty)
@include('progression._tree-toolbar', ['target' => 'catalogo'])
<div data-tree="catalogo">
@foreach($knowledgeLabels as $type => $label)
@if($trees->has($type))
<section class="section-block" data-tree-block><div class="section-heading"><h2>{{ $label }}</h2><p>{{ $trees[$type]->count() }} grupo(s) principal(is) · {{ $trees[$type]->sum('count') }} técnica(s) ou item(ns). Toque na seta para abrir um grupo.</p></div>
<div class="card tree-card">@include('progression._tree', ['nodes' => $trees[$type]->all(), 'mode' => 'catalog', 'root' => true])</div></section>
@endif
@endforeach
<p class="tree-no-results" data-tree-empty hidden>Nenhum item com esse nome.</p>
</div>
@push('scripts')<script src="{{ asset('js/technique-tree.js') }}" defer></script>@endpush
@endif
@endsection
