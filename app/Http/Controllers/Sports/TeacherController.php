<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\TeacherRequest;
use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Person;
use App\Models\TeacherProfile;
use App\Services\Sports\TeacherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TeacherController extends Controller
{
    public function index(Association $association): View
    {
        $teachers = TeacherProfile::query()->whereHas('person.associations', fn ($query) => $query->whereKey($association->id))
            ->with(['person', 'martialArts.martialArt', 'invitations' => fn ($query) => $query->where('association_id', $association->id)->latest('id')])
            ->paginate(20);

        return view('sports.teachers.index', compact('association', 'teachers'));
    }

    public function create(Association $association): View
    {
        $people = Person::query()->whereHas('associations', fn ($query) => $query->whereKey($association->id))->orderBy('full_name')->limit(200)->get();
        $martialArts = MartialArt::query()->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->where('status', 'active')->with('ranks')->orderBy('name')->get();

        return view('sports.teachers.create', compact('association', 'people', 'martialArts'));
    }

    public function store(TeacherRequest $request, Association $association, TeacherService $service): RedirectResponse
    {
        $service->create($association, $request->validated());

        return redirect()->route('associations.teachers.index', $association)->with('status', 'Professor e habilitação salvos.');
    }
}
