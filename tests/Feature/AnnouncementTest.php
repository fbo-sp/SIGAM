<?php

namespace Tests\Feature;

use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\StudentGuardian;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_teacher_sends_to_own_class_with_inbox_email_and_no_duplicate(): void
    {
        Mail::fake();
        [$anaUser, $ana] = $this->sender('Professora Ana');
        [, $bruno] = $this->teacher('Professor Bruno');
        $group = $this->group($ana);
        $other = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma B', 'main_teacher_id' => $bruno->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        $withAccount = $this->student('A-1');
        $portal = $this->portalUser($withAccount->person);
        $child = $this->student('A-2');
        $sibling = $this->student('A-3');
        $outside = $this->student('B-1');
        $mother = $this->person('Mãe Silva');
        $mother->update(['email' => 'mae@example.com']);
        foreach ([$child, $sibling] as $kid) {
            StudentGuardian::query()->create(['student_id' => $kid->id, 'guardian_person_id' => $mother->id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'can_receive_notifications' => true, 'start_date' => '2026-01-01']);
            $this->enroll($group, $kid);
        }
        $this->enroll($group, $withAccount);
        $this->enroll($other, $outside);
        $form = ['target_type' => 'classes', 'class_ids' => [$group->id], 'include' => ['student', 'guardian'], 'title' => 'Treino no sábado', 'body' => "Haverá treino extra.\nTragam água.", 'send_email' => 1];

        $this->actingAs($anaUser)->get(route('operation.announcements.create', $this->dojo))->assertOk()
            ->assertSee('Minhas turmas')->assertSee('Turma A')->assertDontSee('Turma B')->assertDontSee('Professores');
        $this->post(route('operation.announcements.preview', $this->dojo), $form)->assertOk()
            ->assertSee('Vai para 4 pessoa(s)')->assertSee('Mãe Silva')->assertDontSee('B-1');
        $this->post(route('operation.announcements.preview', $this->dojo), [...$form, 'class_ids' => [$other->id]])->assertSessionHasErrors('class_ids');
        $this->post(route('operation.announcements.preview', $this->dojo), [...$form, 'target_type' => 'all'])->assertSessionHasErrors('target_type');

        $token = (string) Str::uuid();
        $this->post(route('operation.announcements.store', $this->dojo), [...$form, 'token' => $token])->assertRedirect()->assertSessionHas('status');
        $this->post(route('operation.announcements.store', $this->dojo), [...$form, 'token' => $token])->assertRedirect();

        $announcement = Announcement::query()->sole();
        $this->assertSame(['teacher', 4, $this->dojo->id], [$announcement->sender_level, $announcement->recipient_count, $announcement->dojo_id]);
        $this->assertSame(1, AnnouncementRecipient::query()->where('person_id', $mother->id)->where('email_status', 'pending')->count());
        $this->assertSame(1, AnnouncementRecipient::query()->where('email', 'mae@example.com')->count());
        $this->assertDatabaseHas('announcement_recipients', ['person_id' => $withAccount->person_id, 'user_id' => $portal->id, 'email' => mb_strtolower($portal->email)]);
        $this->assertDatabaseHas('announcement_recipients', ['person_id' => $child->person_id, 'user_id' => null, 'email_status' => 'none']);
        $this->assertDatabaseMissing('announcement_recipients', ['person_id' => $outside->person_id]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'announcement.sent']);

        $this->actingAs($portal)->get(route('notifications.index'))->assertOk()->assertSee('Treino no sábado')->assertSee('Tragam água.')->assertSee('Enviado por '.$anaUser->name);
        $this->artisan('sigam:notifications:send')->assertExitCode(0);
        Mail::assertSent(AnnouncementMail::class, fn (AnnouncementMail $mail) => $mail->hasTo('mae@example.com') && str_contains($mail->render(), 'Tragam água.'));
        Mail::assertSent(AnnouncementMail::class, 2);
        $this->assertSame(2, AnnouncementRecipient::query()->where('email_status', 'sent')->count());

        $this->actingAs($anaUser)->get(route('operation.announcements.show', [$this->dojo, $announcement]))->assertOk()
            ->assertSee('Leram no JUDOMA')->assertSee('Mãe Silva')->assertSee('e-mail enviado')->assertSee('Sem conta');
    }

    public function test_dojo_manager_reaches_dojo_and_teachers_and_sees_teacher_announcements(): void
    {
        Mail::fake();
        [$anaUser, $ana] = $this->sender('Professora Ana');
        $manager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($manager, $this->role('dojo', ['dojo.access', 'class.manage', 'announcement.send']));
        $student = $this->student('A-1');
        $farDojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $far = $this->student('S-1');
        $far->dojoHistory()->update(['dojo_id' => $farDojo->id]);
        $this->enroll($this->group($ana), $student);

        $this->actingAs($anaUser)->post(route('operation.announcements.store', $this->dojo), ['target_type' => 'people', 'student_ids' => [$student->id], 'include' => ['student'], 'title' => 'Do professor', 'body' => 'Oi', 'token' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs($manager)->get(route('operation.announcements.create', $this->dojo))->assertOk()->assertSee('Todo o dojo')->assertSee('Professores');
        $this->post(route('operation.announcements.store', $this->dojo), ['target_type' => 'all', 'include' => ['student', 'teacher'], 'title' => 'Feriado', 'body' => 'Sem aula na sexta.', 'send_email' => 0, 'token' => (string) Str::uuid()])->assertRedirect();

        $holiday = Announcement::query()->where('title', 'Feriado')->sole();
        $this->assertSame('dojo', $holiday->sender_level);
        $this->assertEqualsCanonicalizing([$student->person_id, $ana->person_id], $holiday->recipients()->pluck('person_id')->all());
        $this->assertSame(0, $holiday->recipients()->where('email_status', '!=', 'none')->count());
        $this->get(route('operation.announcements.index', $this->dojo))->assertSee('Feriado')->assertSee('Do professor');
        $this->actingAs($anaUser)->get(route('operation.announcements.index', $this->dojo))->assertSee('Do professor')->assertDontSee('Feriado');
        $this->get(route('operation.announcements.show', [$this->dojo, $holiday]))->assertNotFound();
    }

    public function test_association_targets_dojos_and_people_and_respects_email_preference(): void
    {
        Mail::fake();
        $admin = $this->associationUser(['announcement.send']);
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        NotificationPreference::query()->create(['user_id' => $anaUser->id, 'event_type' => 'announcement.sent', 'internal_enabled' => true, 'email_enabled' => false]);
        $student = $this->student('A-1');

        $this->actingAs($admin)->get(route('associations.announcements.create', $this->association))->assertOk()->assertSee('Dojo Norte')->assertSee('Professora Ana');
        $this->post(route('associations.announcements.store', $this->association), ['target_type' => 'dojos', 'dojo_ids' => [$this->dojo->id], 'include' => ['student', 'teacher'], 'title' => 'Exame de faixa', 'body' => 'Inscrições abertas.', 'send_email' => 1, 'token' => (string) Str::uuid()])->assertRedirect();
        $announcement = Announcement::query()->sole();
        $this->assertSame(['association', null], [$announcement->sender_level, $announcement->dojo_id]);
        $this->assertDatabaseHas('announcement_recipients', ['person_id' => $ana->person_id, 'email_status' => 'none']);
        $this->assertNotNull(AnnouncementRecipient::query()->where('person_id', $ana->person_id)->value('notification_id'));
        $this->assertDatabaseHas('announcement_recipients', ['person_id' => $student->person_id, 'user_id' => null]);

        Notification::query()->where('user_id', $anaUser->id)->update(['read_at' => now()]);
        $this->get(route('associations.announcements.show', [$this->association, $announcement]))->assertOk()->assertSee('Leu');

        $this->post(route('associations.announcements.preview', $this->association), ['target_type' => 'people', 'teacher_ids' => [$ana->id], 'title' => 'Reunião', 'body' => 'Amanhã.'])->assertOk()->assertSee('Vai para 1 pessoa(s)');
        $this->post(route('associations.announcements.preview', $this->association), ['target_type' => 'people', 'student_ids' => [999999], 'include' => ['student'], 'title' => 'X', 'body' => 'Y'])->assertSessionHasErrors('student_ids');
    }

    public function test_accounts_without_permission_cannot_send(): void
    {
        [$teacherUser] = $this->teacher('Sem permissão');
        $outsider = $this->associationUser(['dashboard.teacher']);

        $this->actingAs($teacherUser)->get(route('operation.announcements.create', $this->dojo))->assertForbidden();
        $this->actingAs($outsider)->get(route('associations.announcements.index', $this->association))->assertForbidden();
        $this->actingAs($outsider)->post(route('operation.announcements.store', $this->dojo), ['target_type' => 'all', 'title' => 'X', 'body' => 'Y', 'token' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_teacher_students_tab_links_to_announcement_and_portal_highlights_unread(): void
    {
        [$anaUser, $ana] = $this->sender('Professora Ana');
        [$brunoUser, $bruno] = $this->teacher('Professor Bruno');
        $group = $this->group($ana);
        $other = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma B', 'main_teacher_id' => $bruno->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        $first = $this->student('A-1');
        $second = $this->student('A-2');
        $portal = $this->portalUser($first->person);
        $this->enroll($group, $first);
        $this->enroll($group, $second);
        $this->enroll($other, $second);
        foreach ([$group, $other] as $class) {
            ClassSession::query()->create(['class_group_id' => $class->id, 'session_date' => '2026-10-28', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        }
        foreach ([$anaUser, $brunoUser] as $user) {
            $user->roles()->attach($this->role('association', ['dashboard.teacher']), ['association_id' => $this->association->id]);
        }

        $row = route('operation.announcements.create', [$this->dojo, 'target_type' => 'people', 'student_ids' => [$first->id]]);
        $list = route('operation.announcements.create', [$this->dojo, 'target_type' => 'people', 'student_ids' => [$first->id, $second->id]]);
        $this->actingAs($anaUser)->get(route('teacher.students'))->assertOk()->assertSee('href="'.e($row).'"', false)
            ->assertSee('Avisar estes 2 alunos')->assertSee('href="'.e($list).'"', false);
        $this->get($list)->assertOk()->assertSee('value="'.$first->id.'" checked', false)->assertSee('value="'.$second->id.'" checked', false);
        $this->actingAs($brunoUser)->get(route('teacher.students'))->assertOk()->assertSee('A-2')->assertDontSee('Avisar este');

        $home = route('portal.students.show', $first);
        $this->actingAs($portal)->get($home)->assertOk()->assertSee('Nada novo')->assertDontSee('has-unread');
        $this->actingAs($anaUser)->post(route('operation.announcements.store', $this->dojo), ['target_type' => 'people', 'student_ids' => [$first->id], 'include' => ['student'], 'title' => 'Treino cancelado', 'body' => 'Chuva forte.', 'token' => (string) Str::uuid()])->assertRedirect();
        $this->post(route('operation.announcements.store', $this->dojo), ['target_type' => 'people', 'student_ids' => [$first->id], 'include' => ['student'], 'title' => 'Lida antes', 'body' => 'Ok.', 'token' => (string) Str::uuid()])->assertRedirect();
        Notification::query()->where('user_id', $portal->id)->where('subject', 'Lida antes')->update(['read_at' => now()]);
        $this->actingAs($portal)->get($home)->assertOk()->assertSee('1 novo')->assertSee('Treino cancelado')
            ->assertSee('portal-shortcut has-unread', false)->assertSee('avisos novos');
        $this->get(route('notifications.index'))->assertOk()->assertSeeInOrder(['Treino cancelado', 'Lida antes']);

        Notification::query()->where('user_id', $portal->id)->update(['read_at' => now()]);
        $this->get($home)->assertOk()->assertSee('Nada novo')->assertDontSee('has-unread');
    }

    /** @return array{User, TeacherProfile} */
    private function sender(string $name): array
    {
        [$user, $teacher] = $this->teacher($name);
        $this->grant($user, $this->role('teacher', ['announcement.send']));

        return [$user, $teacher];
    }
}
