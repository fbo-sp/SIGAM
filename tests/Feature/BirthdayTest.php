<?php

namespace Tests\Feature;

use App\Http\Controllers\People\BirthdayController;
use App\Models\AuditLog;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\Dojo;
use App\Models\Student;
use App\Services\People\BirthdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class BirthdayTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_feb_29_birthday_shows_on_feb_28_in_common_years(): void
    {
        $birth = Carbon::parse('2016-02-29');
        $this->assertSame(28, BirthdayService::dayIn($birth, 2027));
        $this->assertSame(29, BirthdayService::dayIn($birth, 2028));
        $this->assertTrue(BirthdayService::isOn($birth, Carbon::parse('2027-02-28')));
        $this->assertFalse(BirthdayService::isOn($birth, Carbon::parse('2028-02-28')));
        $this->assertTrue(BirthdayService::isOn($birth, Carbon::parse('2028-02-29')));
        $this->assertFalse(BirthdayService::isOn(null, Carbon::parse('2028-02-29')));

        $this->student('F-1', '2016-02-29');
        $manager = $this->associationUser(['association.view', 'student.view']);
        $rows = app(BirthdayService::class)->month(app(BirthdayService::class)->association($manager, $this->association), 2027, 2);
        $this->assertSame([[28, 11]], $rows->map(fn (array $row) => [$row['day'], $row['age']])->all());
    }

    public function test_association_page_lists_active_students_and_teachers_of_the_month(): void
    {
        [, $ana] = $this->teacher('Professora Ana');
        $ana->person->update(['birth_date' => '1985-10-20']);
        $group = $this->group($ana);
        $this->enroll($group, $this->student('A-1', '2016-10-15'));
        $this->student('B-1', '2010-10-03');
        $this->student('C-1', '2012-11-01');
        $this->student('D-1', '2011-10-22')->update(['status' => 'inactive']);
        $this->student('E-1', null);
        $other = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);

        $manager = $this->associationUser(['association.view', 'student.view']);
        $this->actingAs($manager)->get(route('associations.birthdays', $this->association))->assertOk()
            ->assertSeeInOrder(['3 aniversariante(s) em outubro · 1 hoje', 'B-1', 'faz 16', 'A-1', 'Turma A', 'Hoje 🎂', 'Professora Ana', 'Professor · Turma A', 'faz 41'])
            ->assertDontSee('C-1')->assertDontSee('D-1')->assertDontSee('E-1');

        $this->get(route('associations.birthdays', [$this->association, 'mes' => '2026-11']))->assertOk()->assertSee('C-1')->assertDontSee('A-1');
        $this->get(route('associations.birthdays', [$this->association, 'mostrar' => 'professores']))->assertOk()->assertSee('Professora Ana')->assertDontSee('B-1');
        $this->get(route('associations.birthdays', [$this->association, 'turma' => $group->id]))->assertOk()->assertSee('A-1')->assertSee('Professora Ana')->assertDontSee('B-1');
        $this->get(route('associations.birthdays', [$this->association, 'dojo' => $other->id]))->assertOk()->assertSee('Nenhum aniversariante neste mês.');

        $this->actingAs($this->associationUser(['association.view']))->get(route('associations.birthdays', $this->association))->assertForbidden();
    }

    public function test_dojo_manager_sees_only_students_of_the_dojo(): void
    {
        $this->student('A-1', '2016-10-15');
        $other = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $outside = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person('Z-9', '2015-10-16')->id, 'registration_number' => 'Z-9', 'entry_date' => '2026-01-01', 'primary_dojo_id' => $other->id, 'status' => 'active']);
        $outside->dojoHistory()->create(['dojo_id' => $other->id, 'start_date' => '2026-01-01', 'is_primary' => true]);

        $manager = $this->associationUser([]);
        $manager->update(['dojo_id' => $this->dojo->id]);
        $this->grant($manager, $this->role('dojo', ['dojo.access', 'student.view']));
        $this->actingAs($manager)->get(route('operation.birthdays', $this->dojo))->assertOk()->assertSee('A-1')->assertSee('Sem turma')->assertDontSee('Z-9');
        $this->get(route('operation.birthdays', $other))->assertForbidden();
        $this->get(route('operation.home', $this->dojo))->assertOk()->assertSee(route('operation.birthdays', $this->dojo));
    }

    public function test_teacher_today_screen_shows_notice_and_month_list(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $anaUser->roles()->attach($this->role('association', ['dashboard.teacher']), ['association_id' => $this->association->id]);
        [$brunoUser, $bruno] = $this->teacher('Professor Bruno');
        $bruno->person->update(['birth_date' => '1990-10-28']);
        $group = $this->group($ana);
        ClassGroupTeacher::query()->create(['class_group_id' => $group->id, 'teacher_profile_id' => $bruno->id, 'role_type' => 'assistant', 'start_date' => '2026-09-01']);
        $clara = $this->student('Clara', '2016-10-15');
        $clara->person->update(['hide_birthday' => true]);
        $this->enroll($group, $clara);
        $this->student('Fora', '2016-10-15');
        $group->sessions()->create(['session_date' => '2026-10-15', 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'planned', 'attendance_state' => 'open']);

        $this->actingAs($anaUser)->get(route('dashboard'))->assertOk()
            ->assertSee('Hoje é aniversário de Clara')->assertSee('Faz 10 anos · Turma A, aula hoje às 09:00')
            ->assertSeeInOrder(['Aniversariantes de outubro', 'Clara', 'Professor Bruno', 'Professor auxiliar · Turma A', 'faz 36', 'Ver outros meses'])
            ->assertDontSee('Fora');
        $this->get(route('teacher.birthdays', ['mes' => '2026-10']))->assertOk()->assertSee('Clara')->assertSee('Professor Bruno')->assertDontSee('Fora');

        [$loneUser] = $this->teacher('Professor Sem Turma');
        $this->actingAs($loneUser)->get(route('teacher.birthdays'))->assertForbidden();
    }

    public function test_portal_shows_classmates_teachers_and_own_birthday(): void
    {
        [, $ana] = $this->teacher('Professora Ana');
        $ana->person->update(['birth_date' => '1985-10-15']);
        $group = $this->group($ana);
        $other = ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma B', 'start_date' => '2026-09-01', 'status' => 'active']);
        $clara = $this->student('Clara Ribeiro', '2016-10-15');
        $bruno = $this->student('Bruno Lima', '2015-03-02');
        $outra = $this->student('Outra Turma', '2016-10-15');
        $this->enroll($group, $clara);
        $this->enroll($group, $bruno);
        $this->enroll($other, $outra);

        $brunoUser = $this->portalUser($bruno->person);
        $this->actingAs($brunoUser)->get(route('portal.students.show', $bruno))->assertOk()
            ->assertSee('Hoje é aniversário de Clara Ribeiro')->assertSee('Colega da turma Turma A. Dê os parabéns no treino!')
            ->assertSee('Hoje é aniversário de Professora Ana')->assertSee('Professor da turma Turma A')
            ->assertDontSee('Outra Turma')->assertDontSee('Faz 10')->assertDontSee('Feliz aniversário');

        $this->post(route('birthdays.dismiss'))->assertRedirect()->assertCookie(BirthdayController::DISMISS_COOKIE, '2026-10-15');
        $this->withCookie(BirthdayController::DISMISS_COOKIE, '2026-10-15')->get(route('portal.students.show', $bruno))->assertOk()->assertDontSee('Hoje é aniversário');

        $claraUser = $this->portalUser($clara->person);
        $this->actingAs($claraUser)->get(route('portal.students.show', $clara))->assertOk()
            ->assertSee('Feliz aniversário, Clara!')->assertSee('Toda a equipe do Dojo Norte deseja um ótimo dia.')
            ->assertDontSee('Hoje é aniversário de Clara');
    }

    public function test_student_hides_own_birthday_from_classmates_only(): void
    {
        [, $ana] = $this->teacher('Professora Ana');
        $group = $this->group($ana);
        $clara = $this->student('Clara Ribeiro', '2016-10-15');
        $bruno = $this->student('Bruno Lima', '2015-03-02');
        $this->enroll($group, $clara);
        $this->enroll($group, $bruno);
        $claraUser = $this->portalUser($clara->person);

        $this->actingAs($claraUser)->get(route('portal.students.section', [$clara, 'dados']))->assertOk()->assertSee('Esconder dos colegas');
        $this->put(route('portal.students.birthday.update', $clara), ['hide_birthday' => 1])->assertRedirect()->assertSessionHas('status', 'Aniversário escondido dos colegas.');
        $this->assertTrue($clara->person->fresh()->hide_birthday);
        $this->assertSame(1, AuditLog::query()->where('event_type', 'person.birthday_privacy_changed')->count());
        $this->put(route('portal.students.birthday.update', $clara), ['hide_birthday' => 1]);
        $this->assertSame(1, AuditLog::query()->where('event_type', 'person.birthday_privacy_changed')->count());
        $this->get(route('portal.students.section', [$clara, 'dados']))->assertSee('Mostrar aos colegas');
        $this->get(route('portal.students.show', $clara))->assertSee('Feliz aniversário, Clara!');

        $this->actingAs($this->portalUser($bruno->person))->get(route('portal.students.show', $bruno))->assertOk()->assertDontSee('Clara Ribeiro');
        $this->put(route('portal.students.birthday.update', $clara), ['hide_birthday' => 0])->assertNotFound();

        $manager = $this->associationUser(['association.view', 'student.view']);
        $this->actingAs($manager)->get(route('associations.birthdays', $this->association))->assertSee('Clara Ribeiro');
    }

    public function test_manager_dashboard_card_counts_today_and_month(): void
    {
        $this->student('Clara Ribeiro', '2016-10-15');
        $this->student('Marcos Vieira', '1991-10-15');
        $this->student('Alice Martins', '2017-10-03');
        $manager = $this->associationUser(['dashboard.association', 'association.view', 'student.view']);

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            ->assertSee('2 aniversariantes')->assertSee('Clara Ribeiro (10) e Marcos Vieira (35)')
            ->assertSee('Ver aniversariantes do mês (3)')->assertSee(route('associations.birthdays', $this->association));

        $this->actingAs($this->associationUser(['dashboard.association']))->get(route('dashboard'))->assertOk()->assertDontSee('aniversariantes do mês');
    }
}
