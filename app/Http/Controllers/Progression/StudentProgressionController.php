<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Student;
use App\Services\Progression\AssessmentService;
use App\Services\Progression\QuizService;
use App\Services\Progression\StudentProgressService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Consulta do acompanhamento técnico pela associação; avaliar é só do professor, no dojo. */
class StudentProgressionController extends Controller
{
    public function show(Request $request, Association $association, Student $student, StudentProgressService $progress, AssessmentService $assessments): View
    {
        abort_unless($student->association_id === $association->id, 404);
        $options = $progress->martialArts($student);
        $link = $options->firstWhere('martial_art_id', $request->integer('modalidade')) ?? $options->first();
        abort_if($link === null, 404);
        $student->load('person');
        $snapshot = $progress->snapshot($association, $student, $link->martialArt);

        return view('progression.student', [
            'student' => $student,
            'martialArt' => $link->martialArt,
            'options' => $options,
            'snapshot' => $snapshot,
            'timeline' => $snapshot['next_rank'] ? $progress->timeline($student, $link->martialArt, $snapshot) : null,
            'history' => $assessments->history($student, $link->martialArt),
            'worked' => $progress->worked($association, $student, $link->martialArt, today()->subDays(90), today()),
            'levels' => AssessmentLevel::labels(),
            'dojo' => null,
            'association' => $association,
            'canAssess' => false,
            'switchUrl' => fn (int $id) => route('associations.students.progression', [$association, $student, 'modalidade' => $id]),
            ...app(QuizService::class)->summary($student, $link->martialArt),
        ]);
    }
}
