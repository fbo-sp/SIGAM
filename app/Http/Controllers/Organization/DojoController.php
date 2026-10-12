<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DojoRequest;
use App\Models\Association;
use App\Models\Dojo;
use App\Services\Organization\DojoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DojoController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $query = $association->dojos()->orderBy('name');
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
        }

        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->query('status'));
        }

        return view('dojos.index', ['association' => $association, 'dojos' => $query->paginate(20)->withQueryString()]);
    }

    public function create(Association $association): View
    {
        return view('dojos.form', ['association' => $association, 'dojo' => new Dojo(['status' => 'active', 'country' => 'BR'])]);
    }

    public function store(DojoRequest $request, Association $association, DojoService $service): RedirectResponse
    {
        $dojo = $service->create($association, $request->validated());

        return redirect()->route('associations.dojos.show', [$association, $dojo])->with('status', 'Dojo cadastrado com sucesso.');
    }

    public function show(Association $association, Dojo $dojo): View
    {
        $this->ensureBelongsToAssociation($association, $dojo);

        return view('dojos.show', compact('association', 'dojo'));
    }

    public function edit(Association $association, Dojo $dojo): View
    {
        $this->ensureBelongsToAssociation($association, $dojo);

        return view('dojos.form', compact('association', 'dojo'));
    }

    public function update(DojoRequest $request, Association $association, Dojo $dojo, DojoService $service): RedirectResponse
    {
        $this->ensureBelongsToAssociation($association, $dojo);
        $service->update($dojo, $request->validated());

        return redirect()->route('associations.dojos.show', [$association, $dojo])->with('status', 'Dados do dojo atualizados.');
    }

    private function ensureBelongsToAssociation(Association $association, Dojo $dojo): void
    {
        abort_unless($dojo->association_id === $association->id, 404);
    }
}
