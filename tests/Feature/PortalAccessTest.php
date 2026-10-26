<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Championship;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentMartialArt;
use App\Models\StudentRankHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_only_sees_own_record_and_portal_requires_permission(): void
    {
        [$association, $dojo, $student, $user] = $this->studentContext();
        $other = $this->student($association, $dojo, 'Outro aluno');

        $this->actingAs($user)->get(route('portal.index'))->assertRedirect(route('portal.students.show', $student));
        $this->get(route('portal.students.show', $student))->assertOk()->assertSee('Turmas e horários')->assertSee('Financeiro')->assertSee('Campeonatos')->assertDontSee($other->person->full_name);
        $this->get(route('portal.students.section', [$other, 'financeiro']))->assertNotFound();
        $this->get(route('portal.students.show', $other))->assertNotFound();

        $withoutPermission = User::factory()->create(['association_id' => $association->id, 'person_id' => $student->person_id]);
        $this->actingAs($withoutPermission)->get(route('portal.index'))->assertForbidden();
    }

    public function test_guardian_only_sees_active_authorized_dependents(): void
    {
        [$association, $dojo, $student] = $this->studentContext(false);
        $expired = $this->student($association, $dojo, 'Vínculo encerrado');
        $guardian = Person::query()->create(['full_name' => 'Responsável autorizado']);
        $association->people()->attach($guardian, ['role_type' => 'guardian', 'start_date' => today()->subYear(), 'status' => 'active']);
        StudentGuardian::query()->create(['student_id' => $student->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'start_date' => today()->subYear()]);
        StudentGuardian::query()->create(['student_id' => $expired->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'start_date' => today()->subYear(), 'end_date' => today()->subDay()]);
        $user = $this->portalUser($association, $guardian);

        $this->actingAs($user)->get(route('portal.index'))->assertRedirect(route('portal.students.show', $student));
        $this->get(route('portal.students.show', $expired))->assertNotFound();
    }

    public function test_contact_update_is_limited_to_whitelisted_fields_and_legal_guardian(): void
    {
        [$association, $dojo, $student] = $this->studentContext(false);
        $guardian = Person::query()->create(['full_name' => 'Responsável legal']);
        $association->people()->attach($guardian, ['role_type' => 'guardian', 'start_date' => today()->subYear(), 'status' => 'active']);
        StudentGuardian::query()->create(['student_id' => $student->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'father', 'is_legal_guardian' => true, 'start_date' => today()->subYear()]);
        $user = $this->portalUser($association, $guardian);

        $this->actingAs($user)->put(route('portal.students.contact.update', $student), [
            'email' => 'NOVO@EXEMPLO.COM', 'mobile' => '(11) 99999-1111', 'full_name' => 'Nome adulterado',
        ])->assertSessionHasNoErrors();

        $person = $student->person->fresh();
        $this->assertSame('novo@exemplo.com', $person->email);
        $this->assertSame('11999991111', $person->mobile);
        $this->assertNotSame('Nome adulterado', $person->full_name);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'portal.contact.updated', 'association_id' => $association->id]);

        $guardian->update(['full_name' => 'Responsável sem poder legal']);
        $student->guardians()->update(['is_legal_guardian' => false]);
        $this->put(route('portal.students.contact.update', $student), ['email' => 'bloqueado@exemplo.com'])->assertForbidden();
    }

    public function test_private_document_download_checks_student_relationship(): void
    {
        Storage::fake('local');
        [$association, $dojo, $student, $user] = $this->studentContext();
        $other = $this->student($association, $dojo, 'Outro titular');
        $type = DocumentType::query()->create(['association_id' => $association->id, 'name' => 'Atestado', 'status' => 'active']);
        $document = $this->document($association, $student, $type, $user, 'portal/autorizado.pdf');
        $otherDocument = $this->document($association, $other, $type, $user, 'portal/outro.pdf');
        Storage::disk('local')->put($document->storage_path, 'arquivo');
        Storage::disk('local')->put($otherDocument->storage_path, 'outro');

        $this->actingAs($user)->get(route('portal.students.documents.download', [$student, $document]))->assertOk();
        $this->get(route('portal.students.documents.download', [$student, $otherDocument]))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'portal.document.downloaded', 'entity_id' => $document->id]);
    }

    public function test_portal_registration_forces_authorized_student_and_reuses_domain_rules(): void
    {
        [$association, $dojo, $student, $user] = $this->studentContext();
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô', 'status' => 'active']);
        $rank = Rank::query()->create(['martial_art_id' => $art->id, 'name' => 'branca', 'display_name' => 'Faixa branca', 'sort_order' => 1, 'status' => 'active']);
        $practice = StudentMartialArt::query()->create(['student_id' => $student->id, 'martial_art_id' => $art->id, 'start_date' => today()->subYear(), 'status' => 'active', 'current_rank_id' => $rank->id]);
        StudentRankHistory::query()->create(['student_martial_art_id' => $practice->id, 'rank_id' => $rank->id, 'achieved_at' => today()->subYear(), 'source_type' => 'initial_registration']);
        $championship = Championship::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Copa do Portal', 'championship_type' => 'internal', 'organizer' => 'Associação', 'location_name' => 'Ginásio', 'start_date' => today()->addMonth(), 'end_date' => today()->addMonth(), 'registration_open_at' => now()->subHour(), 'registration_close_at' => now()->addWeek(), 'default_fee' => '25.00', 'allow_competitor' => true, 'status' => 'open', 'created_by' => $user->id]);

        $this->actingAs($user)->post(route('portal.students.championships.register', [$student, $championship]), ['category' => 'Adulto', 'weight' => '70,50', 'rank_id' => $rank->id, 'person_id' => 999999, 'student_id' => 999999])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('championship_registrations', ['championship_id' => $championship->id, 'student_id' => $student->id, 'person_id' => $student->person_id, 'participation_type' => 'competitor', 'weight' => '70.50', 'registration_fee' => '25.00']);
    }

    public function test_portal_user_starts_at_portal_home_and_reaches_each_section(): void
    {
        [$association, $dojo, $student, $user] = $this->studentContext();
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('portal.index'));
        $this->get(route('portal.students.show', $student))->assertOk()
            ->assertSee('Próxima aula')->assertSee('Frequência no mês')->assertSee('Mensalidade')->assertSee('Em dia')
            ->assertSee(route('portal.students.section', [$student, 'documentos']), false)->assertSee('class="portal-tabbar"', false)
            ->assertDontSee('Salvar contatos')->assertDontSee('Ver pagamentos');
        foreach (['turmas' => 'Turmas e horários', 'frequencia' => 'Frequência', 'financeiro' => 'Nenhuma cobrança encontrada', 'campeonatos' => 'Campeonatos', 'historico' => 'Nenhum histórico registrado', 'documentos' => 'Nenhum documento disponível', 'dados' => 'Salvar contatos'] as $section => $text) {
            $this->get(route('portal.students.section', [$student, $section]))->assertOk()->assertSee($text)->assertSee('← Início');
        }
        $this->get('/portal/alunos/'.$student->id.'/qualquer')->assertNotFound();

        $staff = $this->portalUser($association, Person::query()->create(['full_name' => 'Gestora com portal']));
        $permission = Permission::query()->firstOrCreate(['code' => 'dashboard.association'], ['description' => 'Painel']);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestão', 'code' => 'gestao_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync([$permission->id]);
        $staff->roles()->attach($role, ['association_id' => $association->id]);
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()->assertSee('Painel da associação');
    }

    public function test_guardian_with_several_students_chooses_first(): void
    {
        [$association, $dojo, $first] = $this->studentContext(false);
        $second = $this->student($association, $dojo, 'Segundo filho');
        $guardian = Person::query()->create(['full_name' => 'Mãe de dois']);
        $association->people()->attach($guardian, ['role_type' => 'guardian', 'start_date' => today()->subYear(), 'status' => 'active']);
        foreach ([$first, $second] as $child) {
            StudentGuardian::query()->create(['student_id' => $child->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'mother', 'is_legal_guardian' => true, 'start_date' => today()->subYear()]);
        }
        $user = $this->portalUser($association, $guardian);

        $this->actingAs($user)->get(route('portal.index'))->assertOk()->assertSee($first->person->full_name)->assertSee($second->person->full_name);
        $this->get(route('portal.students.section', [$second, 'financeiro']))->assertOk()->assertSee('Consultando:')
            ->assertSee(route('portal.students.section', [$first, 'financeiro']), false);
    }

    private function studentContext(bool $withUser = true): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação Portal '.uniqid(), 'trade_name' => 'Portal']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'P'.uniqid(), 'name' => 'Dojo Portal']);
        $student = $this->student($association, $dojo, 'Aluno do Portal');

        return $withUser ? [$association, $dojo, $student, $this->portalUser($association, $student->person)] : [$association, $dojo, $student];
    }

    private function student(Association $association, Dojo $dojo, string $name): Student
    {
        $person = Person::query()->create(['full_name' => $name.' '.uniqid(), 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'student', 'start_date' => today()->subYear(), 'status' => 'active']);

        return Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'P'.uniqid(), 'entry_date' => today()->subYear(), 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
    }

    private function portalUser(Association $association, Person $person): User
    {
        $permission = Permission::query()->firstOrCreate(['code' => 'portal.access'], ['description' => 'Portal']);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Portal', 'code' => 'portal_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync([$permission->id]);
        $user = User::factory()->create(['association_id' => $association->id, 'person_id' => $person->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }

    private function document(Association $association, Student $student, DocumentType $type, User $user, string $path): Document
    {
        return Document::query()->create(['association_id' => $association->id, 'person_id' => $student->person_id, 'student_id' => $student->id, 'document_type_id' => $type->id, 'original_name' => basename($path), 'stored_name' => (string) Str::uuid(), 'storage_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => 7, 'status' => 'active', 'uploaded_by' => $user->id]);
    }
}
