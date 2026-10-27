<?php

namespace App\Services\Notifications;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\Dojo;
use App\Models\DojoTeacherAssignment;
use App\Models\Notification;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\Person;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\AuditService;
use App\Services\Classes\TeacherWorkspaceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avisos escritos por pessoas, por nível: a associação alcança toda a associação, dojos, turmas ou pessoas;
 * o gestor do dojo, o próprio dojo; o professor, as próprias turmas e os alunos delas. O alcance é sempre
 * recalculado aqui; ids vindos do formulário só restringem dentro do que o nível permite.
 */
class AnnouncementService
{
    public const EVENT = 'announcement.sent';

    public const TARGETS = [
        'all' => 'Todos',
        'dojos' => 'Dojos',
        'classes' => 'Turmas',
        'people' => 'Pessoas',
    ];

    public const ROLES = ['student' => 'Aluno', 'guardian' => 'Responsável', 'teacher' => 'Professor'];

    public function __construct(
        private readonly OperationalScope $scope,
        private readonly TeacherWorkspaceService $workspace,
        private readonly AuditService $audit,
    ) {}

    /** Nível de quem envia: association, dojo ou teacher; 403 quando a conta não pode enviar ali. */
    public function level(User $user, Association $association, ?Dojo $dojo = null): string
    {
        abort_unless($user->isActive() && $association->status === 'active'
            && ($user->association_id === null || $user->association_id === $association->id), 403);
        if ($dojo === null) {
            abort_unless($user->hasPermission('announcement.send', $association->id), 403);

            return 'association';
        }
        abort_unless($dojo->association_id === $association->id, 404);
        if ($this->scope->canDojo($user, $dojo, 'announcement.send')) {
            return 'dojo';
        }
        abort_unless($this->scope->canTeacher($user, $dojo, 'announcement.send'), 403);

        return 'teacher';
    }

    /**
     * O que o formulário oferece em cada nível.
     *
     * @return array{targets: array<string, string>, includes: array<string, string>, dojos: Collection, classes: Collection, students: Collection, teachers: Collection}
     */
    public function options(User $user, Association $association, ?Dojo $dojo, string $level): array
    {
        $targets = match ($level) {
            'association' => self::TARGETS,
            'dojo' => ['all' => 'Todo o dojo', 'classes' => 'Turmas', 'people' => 'Pessoas'],
            default => ['classes' => 'Minhas turmas', 'people' => 'Alunos'],
        };
        $includes = ['student' => 'Alunos', 'guardian' => 'Responsáveis'] + ($level === 'teacher' ? [] : ['teacher' => 'Professores']);

        return [
            'targets' => $targets,
            'includes' => $includes,
            'dojos' => $level === 'association' ? Dojo::query()->where('association_id', $association->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']) : collect(),
            'classes' => $this->classes($user, $association, $dojo, $level)->with('dojo:id,name')->orderBy('name')->get(['id', 'name', 'dojo_id']),
            'students' => $this->students($user, $association, $dojo, $level)->with('person:id,full_name,social_name')->get(['id', 'person_id', 'registration_number'])
                ->sortBy(fn (Student $student) => $student->person->social_name ?: $student->person->full_name)->values(),
            'teachers' => $level === 'teacher' ? collect() : $this->teachers($association, $dojo)->with('person:id,full_name,social_name')->get(['id', 'person_id'])
                ->sortBy(fn (TeacherProfile $teacher) => $teacher->person->social_name ?: $teacher->person->full_name)->values(),
        ];
    }

    /**
     * Destinatários do aviso, uma linha por pessoa.
     *
     * @return Collection<int, array{person: Person, role: string, user: ?User, email: ?string}>
     */
    public function audience(User $user, Association $association, ?Dojo $dojo, string $level, array $data): Collection
    {
        $options = $this->options($user, $association, $dojo, $level);
        $type = $data['target_type'];
        if (! isset($options['targets'][$type])) {
            throw ValidationException::withMessages(['target_type' => 'Escolha para quem enviar.']);
        }
        $include = array_values(array_intersect($data['include'] ?? [], array_keys($options['includes'])));
        $picked = fn (string $key, Collection $allowed, string $message): Collection => tap(collect($data[$key] ?? [])->map(fn ($id) => (int) $id)->unique()->values(), function (Collection $ids) use ($allowed, $key, $message): void {
            if ($ids->isEmpty() || $ids->diff($allowed->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages([$key => $message]);
            }
        });

        $studentQuery = $this->students($user, $association, $dojo, $level);
        $teacherIds = collect();
        $classIds = collect();
        switch ($type) {
            case 'all':
                $teacherIds = $this->teachers($association, $dojo)->pluck('id');
                break;
            case 'dojos':
                $dojoIds = $picked('dojo_ids', $options['dojos'], 'Escolha um ou mais dojos.');
                $studentQuery->whereHas('dojoHistory', fn (Builder $history) => $this->current($history)->whereIn('dojo_id', $dojoIds));
                $teacherIds = $this->currentDojoTeachers($association)->whereIn('dojo_id', $dojoIds)->pluck('teacher_profile_id');
                break;
            case 'classes':
                $classIds = $picked('class_ids', $options['classes'], 'Escolha uma ou mais turmas.');
                $studentQuery->whereIn('id', $this->enrolled($classIds));
                $teacherIds = ClassGroup::query()->whereIn('id', $classIds)->whereNotNull('main_teacher_id')->pluck('main_teacher_id')
                    ->merge(ClassGroupTeacher::query()->whereIn('class_group_id', $classIds)->current()->pluck('teacher_profile_id'));
                break;
            case 'people':
                $studentIds = collect($data['student_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
                $pickedTeachers = collect($data['teacher_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
                if (($studentIds->isEmpty() && $pickedTeachers->isEmpty())
                    || $studentIds->diff($options['students']->pluck('id'))->isNotEmpty()
                    || $pickedTeachers->diff($options['teachers']->pluck('id'))->isNotEmpty()) {
                    throw ValidationException::withMessages(['student_ids' => 'Escolha uma ou mais pessoas da lista.']);
                }
                $studentQuery->whereIn('id', $studentIds);
                $teacherIds = $pickedTeachers;
                // Professor escolhido pelo nome recebe mesmo sem marcar "Professores".
                $include = array_values(array_unique([...array_diff($include, ['teacher']), ...($pickedTeachers->isNotEmpty() ? ['teacher'] : [])]));
                break;
        }
        if ($type !== 'people' && $include === []) {
            throw ValidationException::withMessages(['include' => 'Marque quem recebe: alunos, responsáveis ou professores.']);
        }
        if ($type === 'people' && $studentIds->isNotEmpty() && array_intersect($include, ['student', 'guardian']) === []) {
            throw ValidationException::withMessages(['include' => 'Marque se o aviso vai para o aluno, para os responsáveis ou para ambos.']);
        }

        $studentRows = $studentQuery->get(['id', 'person_id']);
        $people = collect();
        if (in_array('student', $include, true)) {
            $people = $people->merge($studentRows->pluck('person_id')->map(fn ($id) => ['person_id' => $id, 'role' => 'student']));
        }
        if (in_array('teacher', $include, true) && $teacherIds->isNotEmpty()) {
            $active = TeacherProfile::query()->whereIn('id', $teacherIds->unique())->where('status', 'active')
                ->whereIn('id', TeacherAssociationMembership::query()->select('teacher_profile_id')->where('association_id', $association->id)->where('status', 'active'))
                ->pluck('person_id');
            $people = $people->merge($active->map(fn ($id) => ['person_id' => $id, 'role' => 'teacher']));
        }
        if (in_array('guardian', $include, true) && $studentRows->isNotEmpty()) {
            $guardians = $this->current(StudentGuardian::query()->whereIn('student_id', $studentRows->pluck('id'))->where('can_receive_notifications', true))->pluck('guardian_person_id');
            $people = $people->merge($guardians->map(fn ($id) => ['person_id' => $id, 'role' => 'guardian']));
        }
        $people = $people->unique('person_id')->values();
        $persons = Person::query()->whereIn('id', $people->pluck('person_id'))->get(['id', 'full_name', 'social_name', 'email'])->keyBy('id');
        $users = User::query()->where('association_id', $association->id)->where('status', 'active')->whereIn('person_id', $people->pluck('person_id'))->get()->keyBy('person_id');

        return $people->filter(fn (array $row) => $persons->has($row['person_id']))->map(function (array $row) use ($persons, $users): array {
            $person = $persons[$row['person_id']];
            $account = $users->get($person->id);

            return ['person' => $person, 'role' => $row['role'], 'user' => $account, 'email' => $account?->email ?: ($person->email ?: null)];
        })->sortBy(fn (array $row) => $row['person']->social_name ?: $row['person']->full_name)->values();
    }

    /** Envia uma vez por chave: repetir o envio do mesmo formulário devolve o aviso já criado. */
    public function send(User $user, Association $association, ?Dojo $dojo, string $level, array $data, string $key): Announcement
    {
        $existing = Announcement::query()->where('association_id', $association->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $audience = $this->audience($user, $association, $dojo, $level, $data);
        if ($audience->isEmpty()) {
            throw ValidationException::withMessages(['target_type' => 'Ninguém recebe este aviso. Revise a escolha.']);
        }

        try {
            return DB::transaction(function () use ($user, $association, $dojo, $level, $data, $key, $audience): Announcement {
                $target = collect(['dojo_ids', 'class_ids', 'student_ids', 'teacher_ids', 'include'])->mapWithKeys(fn ($field) => [$field => array_values($data[$field] ?? [])])->filter()->all();
                $announcement = Announcement::query()->create([
                    'association_id' => $association->id, 'dojo_id' => $dojo?->id, 'sender_user_id' => $user->id, 'sender_level' => $level,
                    'target_type' => $data['target_type'], 'target' => $target, 'title' => $data['title'], 'body' => $data['body'],
                    'send_email' => (bool) ($data['send_email'] ?? false), 'recipient_count' => $audience->count(), 'idempotency_key' => $key, 'sent_at' => now(),
                ]);
                $event = NotificationEvent::query()->firstOrCreate(
                    ['association_id' => $association->id, 'idempotency_key' => 'announcement:'.$announcement->id],
                    ['event_type' => self::EVENT, 'context' => ['announcement_id' => $announcement->id], 'occurred_at' => now()],
                );
                $preferences = NotificationPreference::query()->where('event_type', self::EVENT)->whereIn('user_id', $audience->pluck('user.id')->filter())->get()->keyBy('user_id');
                $signature = $this->signature($user, $dojo, $level);
                $emails = [];
                foreach ($audience as $row) {
                    $account = $row['user'];
                    $preference = $account ? $preferences->get($account->id) : null;
                    $notification = $account && ($preference?->internal_enabled ?? true)
                        ? Notification::query()->firstOrCreate(
                            ['notification_event_id' => $event->id, 'user_id' => $account->id, 'channel' => 'internal'],
                            ['association_id' => $association->id, 'person_id' => $account->person_id, 'event_type' => self::EVENT, 'subject' => $announcement->title, 'body' => $announcement->body."\n\n".$signature, 'status' => 'sent', 'scheduled_at' => now(), 'sent_at' => now()],
                        ) : null;
                    $email = $row['email'] ? mb_strtolower(trim($row['email'])) : null;
                    $mail = $announcement->send_email && $email && ($preference?->email_enabled ?? true) && ! isset($emails[$email]);
                    if ($mail) {
                        $emails[$email] = true;
                    }
                    AnnouncementRecipient::query()->create([
                        'announcement_id' => $announcement->id, 'person_id' => $row['person']->id, 'user_id' => $account?->id, 'notification_id' => $notification?->id,
                        'role' => $row['role'], 'email' => $mail ? $email : null, 'email_status' => $mail ? 'pending' : 'none', 'email_scheduled_at' => $mail ? now() : null,
                    ]);
                }
                $this->audit->record('announcement.sent', 'create', $announcement, null, ['level' => $level, 'target_type' => $announcement->target_type, 'recipients' => $audience->count(), 'emails' => count($emails)], ['association_id' => $association->id, 'dojo_id' => $dojo?->id]);

                return $announcement;
            });
        } catch (QueryException $exception) {
            // Dois envios simultâneos do mesmo formulário: o segundo encontra o primeiro.
            return Announcement::query()->where('association_id', $association->id)->where('idempotency_key', $key)->first() ?? throw $exception;
        }
    }

    /** Avisos que a conta vê no nível: associação vê todos; dojo, os do dojo; professor, os próprios. */
    public function visible(User $user, Association $association, ?Dojo $dojo, string $level): Builder
    {
        return Announcement::query()->where('association_id', $association->id)
            ->when($level !== 'association', fn (Builder $query) => $query->where('dojo_id', $dojo->id))
            ->when($level === 'teacher', fn (Builder $query) => $query->where('sender_user_id', $user->id));
    }

    /** @return array{total: int, accounts: int, read: int, emails: int, emails_sent: int} */
    public function stats(Announcement $announcement): array
    {
        $recipients = $announcement->recipients()->get(['id', 'user_id', 'notification_id', 'email_status']);

        return [
            'total' => $recipients->count(),
            'accounts' => $recipients->whereNotNull('notification_id')->count(),
            'read' => Notification::query()->whereIn('id', $recipients->pluck('notification_id')->filter())->whereNotNull('read_at')->count(),
            'emails' => $recipients->where('email_status', '!=', 'none')->count(),
            'emails_sent' => $recipients->where('email_status', 'sent')->count(),
        ];
    }

    public function signature(User $user, ?Dojo $dojo, string $level): string
    {
        return 'Enviado por '.$user->name.match ($level) {
            'association' => ' · Associação',
            'dojo' => ' · Gestão do '.$dojo->name,
            default => ' · Professor no '.$dojo->name,
        };
    }

    private function classes(User $user, Association $association, ?Dojo $dojo, string $level): Builder
    {
        return match ($level) {
            'association' => ClassGroup::query()->where('association_id', $association->id)->where('status', 'active'),
            'dojo' => ClassGroup::query()->where('association_id', $association->id)->where('dojo_id', $dojo->id)->where('status', 'active'),
            default => $this->workspace->groups($user)->where('dojo_id', $dojo->id),
        };
    }

    private function students(User $user, Association $association, ?Dojo $dojo, string $level): Builder
    {
        $query = Student::query()->where('association_id', $association->id)->where('status', 'active');

        return match ($level) {
            'association' => $query,
            'dojo' => $query->whereHas('dojoHistory', fn (Builder $history) => $this->current($history)->where('dojo_id', $dojo->id)),
            default => $query->whereIn('id', $this->enrolled($this->classes($user, $association, $dojo, $level)->pluck('id'))),
        };
    }

    private function teachers(Association $association, ?Dojo $dojo): Builder
    {
        $query = TeacherProfile::query()->where('status', 'active')
            ->whereIn('id', TeacherAssociationMembership::query()->select('teacher_profile_id')->where('association_id', $association->id)->where('status', 'active'));

        return $dojo ? $query->whereIn('id', $this->currentDojoTeachers($association)->where('dojo_id', $dojo->id)->select('teacher_profile_id')) : $query;
    }

    private function currentDojoTeachers(Association $association): Builder
    {
        return $this->current(DojoTeacherAssignment::query()->where('association_id', $association->id)->whereNull('revoked_at'));
    }

    private function enrolled(Collection $classIds): Builder
    {
        return $this->current(ClassEnrollment::query()->select('student_id')->whereIn('class_group_id', $classIds)->where('status', 'active'));
    }

    private function current(Builder $query): Builder
    {
        return $query->whereDate('start_date', '<=', today())->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }
}
