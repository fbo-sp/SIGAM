@php
    $sections = [];
    foreach ($techniqueTree as $row) {
        if ($row['depth'] === 0) { $sections[] = ['root' => $row['item'], 'groups' => []]; continue; }
        $s = array_key_last($sections);
        if ($row['depth'] === 1) { $sections[$s]['groups'][] = ['item' => $row['item'], 'children' => []]; continue; }
        $g = array_key_last($sections[$s]['groups']);
        $sections[$s]['groups'][$g]['children'][] = $row;
    }
    $marked = array_flip($markedTechniques);
@endphp
@if($techniqueTree->isNotEmpty() && ($canMarkTechniques || $markedTechniques !== []))
<section class="section-block" id="tecnicas"><div class="section-heading"><h2>Técnicas trabalhadas</h2><p>{{ $canMarkTechniques ? 'Marque os grupos trabalhados. As técnicas específicas são opcionais. Contam para os alunos presentes.' : 'Registradas nesta aula.' }}</p></div>
@if($canMarkTechniques)
<form method="post" action="{{ route('operation.session.techniques', [$dojo, $session]) }}">@csrf
@error('techniques')<div class="alert alert-danger">{{ $message }}</div>@enderror
@foreach($sections as $section)
<fieldset class="card technique-picker"><legend>{{ $section['root']->name }} <small>{{ $section['root']->knowledge_type === 'theoretical' ? 'Teórico' : 'Prático' }}</small></legend>
<div class="chip-options"><label class="chip-option"><input type="checkbox" name="techniques[]" value="{{ $section['root']->id }}" @checked(isset($marked[$section['root']->id]))><span>{{ $section['root']->name }} (geral)</span></label>
@foreach($section['groups'] as $group)<label class="chip-option"><input type="checkbox" name="techniques[]" value="{{ $group['item']->id }}" @checked(isset($marked[$group['item']->id]))><span>{{ $group['item']->name }}</span></label>@endforeach</div>
@foreach($section['groups'] as $group)@if($group['children'] !== [])
@php($open = collect($group['children'])->contains(fn ($child) => isset($marked[$child['item']->id])))
<details class="technique-details" @if($open) open @endif><summary>Técnicas de {{ $group['item']->name }}</summary><div class="technique-checks">
@foreach($group['children'] as $child)<label class="depth-{{ min($child['depth'], 4) }}"><input type="checkbox" name="techniques[]" value="{{ $child['item']->id }}" @checked(isset($marked[$child['item']->id]))> {{ $child['item']->name }}@if($child['item']->kind === 'group') <small>(grupo)</small>@endif</label>@endforeach
</div></details>
@endif @endforeach
</fieldset>
@endforeach
<div class="form-actions"><button class="button button-primary">Salvar técnicas</button></div>
</form>
@else
<div class="card list-card">@foreach($sections as $section)@php($names = collect([$section['root'], ...collect($section['groups'])->flatMap(fn ($g) => [$g['item'], ...collect($g['children'])->pluck('item')])])->filter(fn ($item) => isset($marked[$item->id]))->pluck('name'))@if($names->isNotEmpty())<div class="list-row"><span><strong>{{ $section['root']->name }}</strong><small>{{ $names->join(', ') }}</small></span></div>@endif @endforeach</div>
@endif
</section>
@endif
