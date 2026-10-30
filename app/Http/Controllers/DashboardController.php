<?php

namespace App\Http\Controllers;

use App\Services\Portal\PortalAccessService;
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
        if ($dashboard['kind'] === 'personal' && app(PortalAccessService::class)->canEnter($request->user())) {
            return redirect()->route('portal.index');
        }

        return view('dashboard', compact('dashboard'));
    }
}
