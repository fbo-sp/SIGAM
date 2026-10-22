<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progression\PanelFilterRequest;
use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\MartialArt;
use App\Services\Progression\ProgressionPanelService;
use Illuminate\View\View;

/** Painel da associação: compara dojos e professores no acompanhamento técnico. */
class ProgressionReportController extends Controller
{
    public function index(PanelFilterRequest $request, Association $association, ProgressionPanelService $panels): View
    {
        $martialArts = MartialArt::query()->whereIn('id', ClassGroup::query()->where('association_id', $association->id)->where('status', 'active')->select('martial_art_id'))
            ->orderBy('name')->get();
        $martialArt = $martialArts->firstWhere('id', $request->integer('modalidade')) ?? $martialArts->first();
        [$from, $until] = $request->period();

        return view('progression.report', [
            'association' => $association,
            'martialArts' => $martialArts,
            'martialArt' => $martialArt,
            'from' => $from,
            'until' => $until,
            'comparison' => $martialArt ? $panels->comparison($association, $martialArt, $from, $until) : null,
        ]);
    }
}
