<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Models\Association;
use App\Models\AuditLog;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\People\PendingDataService;
use App\Services\Portal\PortalAccessService;
use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuardianManagementTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private Dojo $dojo;

    private MartialArt $art;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-30 08:00');
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $this->art = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Judô']);
    }

    public function test_association_manager_adds_new_guardian_reuses_by_cpf_and_by_name_with_mobile(): void
    {
        $manager = $this->associationManager();
        $student = $this->student('Clara Ribeiro', '2016-05-01');
        $sister = $this->student('Lia Ribeiro', '2018-05-01');
        $url = route('associations.students.guardians.store', [$this->association, $student]);

        $this->actingAs($manager)->get(route('associations.students.show', [$this->association, $student]))->assertOk()
            ->assertSee('Adicionar responsável')->assertSee('Precisa de responsável mesmo sendo maior de idade')->assertSee('Nenhum responsável vigente');

        $this->post($url, ['mode' => 'new', 'full_name' => 'Marta  Ribeiro', 'cpf' => '529.982.247-25', 'mobile' => '(11) 98888-0000', 'relationship_type' => 'mother', 'is_legal_guardian' => '1', 'is_primary' => '1', 'can_receive_notifications' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Responsável cadastrado e vinculado.');
        $marta = Person::query()->where('cpf', '52998224725')->firstOrFail();
        $this->assertSame(['Marta Ribeiro', '11988880000'], [$marta->full_name, $marta->mobile]);
        $this->assertTrue($marta->associations()->whereKey($this->association->id)->exists());

        $this->post($url, ['mode' => 'new', 'full_name' => 'Marta Ribeiro', 'cpf' => '52998224725', 'relationship_type' => 'mother'])->assertSessionHasErrors('guardian_person_id');
        $this->post(route('associations.students.guardians.store', [$this->association, $sister]), ['mode' => 'new', 'full_name' => 'Outro nome', 'cpf' => '52998224725', 'email' => 'marta@example.com', 'relationship_type' => 'mother'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Responsável vinculado usando o cadastro que já existia.');
        $this->assertSame(['Marta Ribeiro', 'marta@example.com'], [$marta->fresh()->full_name, $marta->fresh()->email]);

        $this->post($url, ['mode' => 'new', 'full_name' => 'José Ribeiro', 'mobile' => '11977771111', 'relationship_type' => 'grandparent', 'can_receive_notifications' => '1'])->assertSessionHasNoErrors();
        $this->post(route('associations.students.guardians.store', [$this->association, $sister]), ['mode' => 'new', 'full_name' => 'josé ribeiro', 'mobile' => '(11) 97777-1111', 'relationship_type' => 'grandparent'])
            ->assertSessionHas('status', 'Responsável vinculado usando o cadastro que já existia.');
        $this->assertSame(1, Person::query()->where('full_name', 'José Ribeiro')->count());
        $this->assertSame(4, StudentGuardian::query()->count());
        $this->assertSame(4, AuditLog::query()->where('event_type', 'student.guardian_added')->count());

        $this->get(route('associations.students.show', [$this->association, $student]))->assertOk()
            ->assertSeeInOrder(['Marta Ribeiro', 'Mãe · desde 30/10/2026 · (11) 98888-0000', 'Legal', 'Principal', 'Sem acesso ao portal', 'José Ribeiro', 'Avô/avó'])
            ->assertSee('Liberar portal');
    }

    public function test_edit_end_and_requires_guardian_flag_are_audited_and_change_pending_data(): void
    {
        $manager = $this->associationManager();
        $student = $this->student('Pedro Adulto', '1990-01-01');
        $pending = fn () => app(PendingDataService::class)->forStudent($student);
        $this->assertNotContains('guardian', $pending());

        $this->actingAs($manager)->post(route('associations.students.guardians.requires', [$this->association, $student]), ['requires_guardian' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($student->fresh()->requires_guardian);
        $this->assertContains('guardian', $pending());
        $this->assertSame(1, AuditLog::query()->where('event_type', 'student.requires_guardian_changed')->count());

        $this->post(route('associations.students.guardians.store', [$this->association, $student]), ['mode' => 'new', 'full_name' => 'Ana Irmã', 'relationship_type' => 'other', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $link = StudentGuardian::query()->firstOrFail();
        $this->assertContains('guardian', $pending());
        $this->put(route('associations.students.guardians.update', [$this->association, $student, $link]), ['relationship_type' => 'tutor', 'is_legal_guardian' => '1', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['tutor', true, false], [$link->fresh()->relationship_type, $link->fresh()->is_legal_guardian, $link->fresh()->can_receive_notifications]);
        $this->assertNotContains('guardian', $pending());

        $this->post(route('associations.students.guardians.end', [$this->association, $student, $link]), ['end_date' => '2026-10-31'])->assertSessionHasErrors('end_date');
        $this->post(route('associations.students.guardians.end', [$this->association, $student, $link]), ['reason' => 'Mudou de cidade'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-30', $link->fresh()->end_date->toDateString());
        $this->travel(1)->days();
        $this->assertContains('guardian', $pending());
        $this->assertDatabaseCount('student_guardians', 1);
        $this->post(route('associations.students.guardians.end', [$this->association, $student, $link]))->assertSessionHasErrors('guardian');
        $this->assertSame(['student.guardian_updated', 'student.guardian_ended'], AuditLog::query()->whereIn('event_type', ['student.guardian_updated', 'student.guardian_ended'])->orderBy('id')->pluck('event_type')->all());
    }

    public function test_dojo_manager_and_teacher_manage_only_their_students(): void
    {
        [$teacherUser, $teacher] = $this->teacher('Professora Ana');
        $own = $this->student('Aluno da turma', '2015-01-01');
        $outside = $this->student('Aluno de outra turma', '2015-01-01');
        ClassEnrollment::query()->create(['class_group_id' => $this->group('Manhã', $teacher)->id, 'student_id' => $own->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        ClassEnrollment::query()->create(['class_group_id' => $this->group('Tarde')->id, 'student_id' => $outside->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        $otherDojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $foreign = $this->student('Aluno do Sul', '2015-01-01', $otherDojo);

        $this->actingAs($teacherUser)->get(route('operation.students.guardians.index', [$this->dojo, $own]))->assertOk()->assertSee('Adicionar responsável')->assertSee('Aluno da turma');
        $this->post(route('operation.students.guardians.store', [$this->dojo, $own]), ['mode' => 'new', 'full_name' => 'Mãe do aluno', 'mobile' => '11955554444', 'relationship_type' => 'mother', 'is_legal_guardian' => '1'])->assertSessionHasNoErrors();
        $this->get(route('operation.students.guardians.index', [$this->dojo, $outside]))->assertForbidden();
        $this->post(route('operation.students.guardians.store', [$this->dojo, $outside]), ['mode' => 'new', 'full_name' => 'Intruso', 'relationship_type' => 'other'])->assertForbidden();
        $this->post(route('associations.students.guardians.store', [$this->association, $own]), ['mode' => 'new', 'full_name' => 'Intruso', 'relationship_type' => 'other'])->assertForbidden();

        $manager = $this->dojoManager();
        $this->actingAs($manager)->get(route('operation.students', $this->dojo))->assertOk()->assertSee(route('operation.students.guardians.index', [$this->dojo, $outside]));
        $mother = Person::query()->where('full_name', 'Mãe do aluno')->firstOrFail();
        // Irmão: o gestor do dojo escolhe entre os responsáveis dos alunos do dojo.
        $this->get(route('operation.students.guardians.index', [$this->dojo, $outside]))->assertOk()->assertSee('Mãe do aluno · (11) 95555-4444');
        $this->post(route('operation.students.guardians.store', [$this->dojo, $outside]), ['mode' => 'existing', 'guardian_person_id' => $mother->id, 'relationship_type' => 'mother'])->assertSessionHasNoErrors();
        $this->post(route('operation.students.guardians.store', [$this->dojo, $foreign]), ['mode' => 'new', 'full_name' => 'Intruso', 'relationship_type' => 'other'])->assertForbidden();
        $this->get(route('operation.students.guardians.index', [$otherDojo, $foreign]))->assertForbidden();

        $stranger = $this->person('Pessoa da associação');
        $this->post(route('operation.students.guardians.store', [$this->dojo, $outside]), ['mode' => 'existing', 'guardian_person_id' => $stranger->id, 'relationship_type' => 'other'])->assertNotFound();
        $this->assertSame(2, StudentGuardian::query()->where('guardian_person_id', $mother->id)->count());
        $this->assertSame($this->dojo->id, AuditLog::query()->where('event_type', 'student.guardian_added')->latest('id')->first()->context['dojo_id']);
    }

    public function test_teacher_releases_portal_and_guardian_with_account_enters_portal_automatically(): void
    {
        Mail::fake();
        [$teacherUser, $teacher] = $this->teacher('Professora Ana');
        $student = $this->student('Aluno da turma', '2015-01-01');
        ClassEnrollment::query()->create(['class_group_id' => $this->group('Manhã', $teacher)->id, 'student_id' => $student->id, 'start_date' => '2026-09-01', 'status' => 'active']);

        $this->actingAs($teacherUser)->post(route('operation.students.guardians.store', [$this->dojo, $student]), [
            'mode' => 'new', 'full_name' => 'Mãe do aluno', 'email' => 'mae@example.com', 'relationship_type' => 'mother', 'is_legal_guardian' => '1', 'release_portal' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Responsável cadastrado e vinculado. Convite do portal enviado para mae@example.com.');
        $account = User::query()->where('email', 'mae@example.com')->firstOrFail();
        $this->assertSame(['pending', [AssociationRoleCatalog::PORTAL]], [$account->status, $account->roles->pluck('code')->all()]);
        Mail::assertSent(AccountActivationMail::class);

        $father = $this->person('Pai do aluno');
        $this->post(route('operation.students.guardians.store', [$this->dojo, $student]), ['mode' => 'new', 'full_name' => 'Pai do aluno', 'relationship_type' => 'father'])->assertSessionHasNoErrors();
        $link = StudentGuardian::query()->where('relationship_type', 'father')->firstOrFail();
        $this->assertNotSame($father->id, $link->guardian_person_id);
        $this->post(route('operation.students.guardians.portal', [$this->dojo, $student, $link]))->assertSessionHasErrors('email');
        $this->post(route('operation.students.guardians.portal', [$this->dojo, $student, $link]), ['email' => 'pai@example.com'])->assertSessionHas('status', 'Convite do portal enviado para pai@example.com.');
        $this->assertSame('pai@example.com', $link->person->fresh()->email);

        // A professora também é mãe de um aluno: entra no portal sem o perfil Portal e ganha o atalho.
        $child = $this->student('Filho da professora', '2017-01-01');
        $this->get(route('portal.index'))->assertForbidden();
        StudentGuardian::query()->create(['student_id' => $child->id, 'guardian_person_id' => $teacherUser->person_id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'start_date' => '2026-10-01']);
        app()->forgetInstance(PortalAccessService::class);
        $this->get(route('portal.index'))->assertRedirect(route('portal.students.show', $child));
        $this->get(route('dashboard'))->assertOk()->assertSee('Meu portal')->assertSee('Meu dependente')->assertSee('Filho · abrir o portal');
    }

    private function associationManager(): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => 'Gestor', 'code' => 'gestor_'.Str::random(6), 'scope_type' => 'association']);
        $role->permissions()->sync(collect(['student.view', 'student.manage', 'person.manage'])->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));
        $user->roles()->attach($role, ['association_id' => $this->association->id]);

        return $user;
    }

    private function dojoManager(): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($user, $this->role('dojo', ['dojo.access', 'student.view', 'student.manage', 'class.view']));

        return $user;
    }

    /** @return array{User, TeacherProfile} */
    private function teacher(string $name): array
    {
        $person = $this->person($name);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $this->association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id]);
        $this->grant($user, $this->role('teacher', ['dojo.access', 'class.view', 'session.view']));
        $this->dashboardRole($user, 'dashboard.teacher');
        DojoTeacherAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'teacher_profile_id' => $teacher->id, 'start_date' => '2026-09-01', 'created_by_user_id' => $user->id]);

        return [$user, $teacher];
    }

    private function dashboardRole(User $user, string $code): void
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => 'Painel', 'code' => 'painel_'.Str::random(6), 'scope_type' => 'association']);
        $role->permissions()->sync([Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id]);
        $user->roles()->attach($role, ['association_id' => $this->association->id]);
    }

    private function role(string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => $scope, 'code' => $scope.'_'.Str::random(8), 'scope_type' => $scope]);
        $role->permissions()->sync(collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));

        return $role;
    }

    private function grant(User $user, Role $role): void
    {
        DojoRoleAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'user_id' => $user->id, 'role_id' => $role->id, 'start_date' => '2026-09-01', 'created_by_user_id' => $user->id]);
    }

    private function person(string $name, ?string $birthDate = '1985-01-01'): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => $birthDate]);
        $this->association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);

        return $person;
    }

    private function student(string $name, ?string $birthDate, ?Dojo $dojo = null): Student
    {
        $dojo ??= $this->dojo;
        $student = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person($name, $birthDate)->id, 'registration_number' => 'ALU-'.Str::random(6), 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $student->martialArts()->create(['martial_art_id' => $this->art->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return $student;
    }

    private function group(string $name, ?TeacherProfile $teacher = null): ClassGroup
    {
        return ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => $name, 'main_teacher_id' => $teacher?->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }
}
