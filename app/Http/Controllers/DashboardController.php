<?php

namespace App\Http\Controllers;

use App\Services\Reporting\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Quem só tem o acesso de aluno ou responsável começa direto no portal. */
    public function __invoke(Request $request, DashboardService $dashboards): View|RedirectResponse
    {
        $dashboard = $dashboards->for($request->user());
        if ($dashboard['kind'] === 'personal' && $request->user()->hasPermission('portal.access', $request->user()->association_id)) {
            return redirect()->route('portal.index');
        }

        return view('dashboard', compact('dashboard'));
    }
}
