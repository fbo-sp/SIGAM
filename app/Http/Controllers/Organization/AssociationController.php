<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\AssociationRequest;
use App\Models\Association;
use App\Services\Organization\AssociationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssociationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Association::query()->orderBy('trade_name');
        if ($request->user()->association_id !== null) {
            $query->whereKey($request->user()->association_id);
        }

        return view('associations.index', ['associations' => $query->paginate(20)]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->hasPermission('association.manage', null), 403);

        return view('associations.form', ['association' => new Association]);
    }

    public function store(AssociationRequest $request, AssociationService $service): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('association.manage', null), 403);
        $association = $service->create($request->validated());

        return redirect()->route('associations.show', $association)->with('status', 'Associação cadastrada com sucesso.');
    }

    public function show(Association $association): View
    {
        return view('associations.show', [
            'association' => $association,
            'dojoCount' => $association->dojos()->count(),
            'peopleCount' => $association->people()->distinct()->count('people.id'),
        ]);
    }

    public function edit(Association $association): View
    {
        return view('associations.form', compact('association'));
    }

    public function update(AssociationRequest $request, Association $association, AssociationService $service): RedirectResponse
    {
        $service->update($association, $request->validated());

        return redirect()->route('associations.show', $association)->with('status', 'Dados da associação atualizados.');
    }
}
