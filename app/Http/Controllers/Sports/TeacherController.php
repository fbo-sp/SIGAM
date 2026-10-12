<?php

namespace App\Http\Controllers\Sports;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\TeacherProfile;
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
}
