<?php

namespace App\Services\Exams;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\RankExam;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\Classes\TeacherWorkspaceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Monta o alcance dos exames a partir das mesmas regras de acesso das telas. */
class ExamScope
{
    public const MANAGE = 'exam.manage';

    public const GRADE = 'exam.grade';

    public function __construct(private readonly OperationalScope $scope, private readonly TeacherWorkspaceService $workspace) {}

    public function association(User $user, Association $association): ExamContext
    {
        abort_unless($user->hasPermission(self::MANAGE, $association->id), 403);

        return new ExamContext('association', $user, $association, null, null);
    }

    public function dojo(User $user, Dojo $dojo): ExamContext
    {
        abort_unless($this->scope->canDojo($user, $dojo, self::MANAGE), 403);
        $dojo->loadMissing('association');
        view()->share('currentAssociation', $dojo->association);

        return new ExamContext('dojo', $user, $dojo->association, $dojo, null);
    }

    /** Professor com perfil ativo e a concessão de banca em algum dojo da associação. */
    public function teacher(User $user): ExamContext
    {
        $teacherId = $this->workspace->teacherId($user);
        abort_unless($teacherId && $this->canGrade($user), 403);
        $association = Association::query()->findOrFail($user->association_id);

        return new ExamContext('teacher', $user, $association, null, $teacherId);
    }

    public function canGrade(User $user): bool
    {
        return $this->workspace->teacherId($user) !== null && $this->workspace->dojos($user)
            ->contains(fn (Dojo $dojo) => $this->scope->canTeacher($user, $dojo, self::GRADE) || $this->scope->canDojo($user, $dojo, self::GRADE));
    }

    /** O exame está no alcance do contexto (senão 404). */
    public function exam(ExamContext $context, RankExam $exam): RankExam
    {
        abort_unless($context->exams()->whereKey($exam->id)->exists(), 404);

        return $exam;
    }

    /**
     * Exames com participante inscrito numa turma ativa em que o professor é principal ou auxiliar vigente.
     *
     * @return Builder
     */
    public static function examsTaughtBy(?int $teacherId)
    {
        $today = today()->toDateString();

        return DB::table('rank_exam_participants')->select('rank_exam_participants.rank_exam_id')
            ->join('class_enrollments', 'class_enrollments.student_id', '=', 'rank_exam_participants.student_id')
            ->join('class_groups', 'class_groups.id', '=', 'class_enrollments.class_group_id')
            ->where('rank_exam_participants.status', '!=', 'removed')->where('class_enrollments.status', 'active')
            ->where('class_enrollments.start_date', '<=', $today)
            ->where(fn ($end) => $end->whereNull('class_enrollments.end_date')->orWhere('class_enrollments.end_date', '>=', $today))
            ->where('class_groups.status', 'active')
            ->where(fn ($teacher) => $teacher->where('class_groups.main_teacher_id', $teacherId ?? 0)
                ->orWhereExists(fn ($assistant) => $assistant->from('class_group_teachers')->whereColumn('class_group_teachers.class_group_id', 'class_groups.id')
                    ->where('class_group_teachers.teacher_profile_id', $teacherId ?? 0)->where('class_group_teachers.role_type', 'assistant')
                    ->where('class_group_teachers.start_date', '<=', $today)
                    ->where(fn ($end) => $end->whereNull('class_group_teachers.end_date')->orWhere('class_group_teachers.end_date', '>=', $today))));
    }
}
