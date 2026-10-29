<?php

namespace Tests\Feature;

use App\Models\Dojo;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\Sports\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class PendingDataTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    private function complete(Student $student): Student
    {
        $student->person->update(['cpf' => null, 'emergency_contact_name' => 'Maria', 'emergency_contact_phone' => '11988887777', 'mobile' => '11977776666',
            'street' => 'Rua A', 'city' => 'São Paulo']);

        return $student;
    }

    public function test_association_page_lists_each_pending_item_and_filters_by_type(): void
    {
        [, $ana] = $this->teacher('Professora Ana');
        $group = $this->group($ana);
        $noBirth = $this->complete($this->student('Sem Data', null));
        $minor = $this->complete($this->student('Menor', '2015-01-01'));
        $noContact = $this->student('Sem Contato', '2000-01-01');
        $ok = $this->complete($this->student('Completo', '2000-01-01'));
        $ok->person->update(['cpf' => '52998224725']);
        foreach ([$noBirth, $minor, $noContact, $ok] as $student) {
            $this->enroll($group, $student);
        }
        $noClass = $this->complete($this->student('Sem Turma', '2000-01-01'));
        $guarded = $this->complete($this->student('Menor Com Pai', '2014-01-01'));
        $this->enroll($group, $guarded);
        $father = $this->person('Pai');
        StudentGuardian::query()->create(['student_id' => $guarded->id, 'guardian_person_id' => $father->id, 'relationship_type' => 'father', 'is_legal_guardian' => true, 'is_primary' => true, 'start_date' => '2026-01-01']);

        $manager = $this->associationUser(['association.view', 'student.view']);
        $response = $this->actingAs($manager)->get(route('associations.pending', $this->association))->assertOk()
            ->assertSee('Todos (5)')->assertSee('Sem nascimento (1)')->assertSee('Menor sem responsável (1)')->assertSee('Sem turma (1)')
            ->assertSee('Sem contato de emergência (1)')->assertSee('Sem telefone (1)')->assertSee('Sem endereço (1)')->assertSee('Sem CPF (5)')
            ->assertDontSee('Completo');
        $this->assertSame(1, substr_count($response->getContent(), 'Menor sem responsável</span>'));

        $this->get(route('associations.pending', [$this->association, 'tipo' => 'birth']))->assertOk()->assertSee('Sem Data')->assertDontSee('Sem Contato</strong>', false);
        $this->get(route('associations.pending', [$this->association, 'busca' => 'Turma']))->assertOk()->assertSee('Sem Turma')->assertSee('Todos (1)');
        $this->get(route('associations.students.show', [$this->association, $noBirth]))->assertOk()->assertSee('Cadastro incompleto')->assertSee('Falta: data de nascimento e CPF.');
        $this->get(route('associations.students.show', [$this->association, $noClass]))->assertOk()->assertSee('Falta: turma e CPF.');

        $this->actingAs($this->associationUser(['association.view']))->get(route('associations.pending', $this->association))->assertForbidden();
    }

    public function test_dashboards_and_teacher_screens_show_pending_data_in_scope(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        $anaUser->roles()->attach($this->role('association', ['dashboard.teacher']), ['association_id' => $this->association->id]);
        $group = $this->group($ana);
        $this->enroll($group, $this->complete($this->student('Sem Data', null)));
        $this->complete($this->student('Fora Da Turma', null));
        $group->sessions()->create(['session_date' => '2026-10-16', 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'planned', 'attendance_state' => 'open']);

        $this->actingAs($anaUser)->get(route('dashboard'))->assertOk()
            ->assertSee('1 aluno das suas turmas com cadastro incompleto')->assertSee('1 sem nascimento e 1 sem CPF. Peça os dados no treino');
        $this->get(route('teacher.pending'))->assertOk()->assertSee('Sem Data')->assertDontSee('Fora Da Turma')->assertSee(route('operation.progression.show', [$this->dojo, Student::query()->where('registration_number', 'Sem Data')->first()]));
        $this->get(route('teacher.students'))->assertOk()->assertSee('Cadastro incompleto (1)');

        $manager = $this->associationUser(['dashboard.association', 'association.view', 'student.view']);
        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('2 alunos com cadastro incompleto')->assertSee('2 sem nascimento')
            ->assertSee(route('associations.pending', $this->association).'?tipo=birth');

        $other = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'S', 'name' => 'Dojo Sul']);
        $dojoManager = $this->associationUser([]);
        $dojoManager->update(['dojo_id' => $this->dojo->id]);
        $this->grant($dojoManager, $this->role('dojo', ['dojo.access', 'student.view', 'student.manage']));
        $this->actingAs($dojoManager)->get(route('operation.pending', $this->dojo))->assertOk()->assertSee('Todos (2)')
            ->assertSee(route('operation.students.person.edit', [$this->dojo, Student::query()->where('registration_number', 'Sem Data')->first()]));
        $this->get(route('operation.pending', $other))->assertForbidden();
    }

    public function test_student_without_birth_date_can_be_activated_and_portal_asks_for_contacts(): void
    {
        $this->association->update(['requires_minor_guardian' => true]);
        $student = $this->student('Sem Data', null);
        $student->update(['status' => 'pre_registration']);
        app(StudentService::class)->activate($this->association, $student);
        $this->assertSame('active', $student->fresh()->status);

        $portal = $this->portalUser($student->person);
        $this->actingAs($portal)->get(route('portal.students.show', $student))->assertOk()
            ->assertSee('Complete seus dados')->assertSee('Falta: contato de emergência e telefone ou celular.')->assertDontSee('data de nascimento');
        $student->person->update(['emergency_contact_name' => 'Maria', 'emergency_contact_phone' => '11988887777', 'mobile' => '11977776666']);
        $this->get(route('portal.students.show', $student))->assertOk()->assertDontSee('Complete seus dados');
    }
}
