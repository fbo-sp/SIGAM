<?php

namespace App\Http\Controllers;

use App\Services\Reporting\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboards): View
    {
        $dashboard = $dashboards->for($request->user());

        return view('dashboard', compact('dashboard'));
    }
}
