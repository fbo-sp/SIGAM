@extends('layouts.app')
@section('title', 'Editar '.$item->name)
@php($knowledgeLabels = ['theoretical' => 'Conhecimento Teórico', 'practical' => 'Conhecimento Prático'])
@section('content')
<nav class="breadcrumbs"><a href="{{ route('associations.catalog.index', $association) }}">Modalidades</a><span>/</span><a href="{{ route('associations.techniques.index', [$association, $martialArt]) }}">Técnicas de {{ $martialArt->name }}</a><span>/</span><span>Editar</span></nav>
<div class="page-heading"><div><span class="eyebrow">{{ $item->knowledgeLabel() }}</span><h1>{{ $item->name }}</h1><p>{{ $path }}</p></div></div>
<form class="card form-grid" method="post" action="{{ route('associations.techniques.update', [$association, $martialArt, $item]) }}">@csrf @method('PUT')
<div class="field field-wide"><label for="parent_id">Dentro de</label><select id="parent_id" name="parent_id"><option value="">Nenhum (grupo principal)</option>@foreach($groups as $id => $groupPath)<option value="{{ $id }}" @selected((int) old('parent_id', $item->parent_id) === $id)>{{ $groupPath }}</option>@endforeach</select>@error('parent_id')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="kind">Tipo de nó</label><select id="kind" name="kind"><option value="item" @selected(old('kind', $item->kind) === 'item')>Técnica ou item avaliado</option><option value="group" @selected(old('kind', $item->kind) === 'group')>Grupo</option></select>@if($hasChildren)<small>Este grupo tem itens dentro.</small>@endif @error('kind')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="knowledge_type">Conhecimento</label><select id="knowledge_type" name="knowledge_type">@foreach($knowledgeLabels as $value => $label)<option value="{{ $value }}" @selected(old('knowledge_type', $item->knowledge_type) === $value)>{{ $label }}</option>@endforeach</select><small>Só vale para grupo principal; mudar aqui muda também os itens dentro dele.</small>@error('knowledge_type')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $item->name) }}" maxlength="160" required>@error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="original_name">Nome original</label><input id="original_name" name="original_name" value="{{ old('original_name', $item->original_name) }}" maxlength="160"></div>
<div class="field field-wide"><label for="description">Descrição para estudo</label><textarea id="description" name="description" rows="3" maxlength="2000">{{ old('description', $item->description) }}</textarea></div>
<div class="field"><label for="video_url">Link de vídeo</label><input id="video_url" name="video_url" type="url" value="{{ old('video_url', $item->video_url) }}" placeholder="https://">@error('video_url')<p class="field-error">{{ $message }}</p>@enderror</div>
<div class="field"><label for="sort_order">Ordem</label><input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $item->sort_order) }}"></div>
<div class="form-actions field-wide"><a class="button button-secondary" href="{{ route('associations.techniques.index', [$association, $martialArt]) }}">Cancelar</a><button class="button button-primary">Salvar</button></div></form>
@endsection
