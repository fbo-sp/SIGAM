<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\DocumentType;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_registers_and_activates_minor_with_guardian_and_private_document_in_one_screen(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context(['student.manage', 'document.manage']);
        $type = DocumentType::query()->create(['association_id' => $association->id, 'name' => 'Identidade']);

        $response = $this->actingAs($user)->post(route('associations.students.direct.store', $association), [
            ...$this->studentPayload($dojo, $art),
            'person' => ['full_name' => 'Aluno Direto', 'birth_date' => '2015-01-10'],
            'guardian_mode' => 'new',
            'guardian' => ['full_name' => 'Responsável Direto'],
            'guardian_relationship' => 'mother',
            'guardian_is_legal' => '1',
            'guardian_is_primary' => '1',
            'finish_action' => 'activate',
            'document_type_id' => $type->id,
            'document_owner' => 'guardian',
            'document_file' => UploadedFile::fake()->createWithContent('identidade.pdf', "%PDF-1.4\nSIGAM"),
        ]);

        $response->assertSessionHasNoErrors();
        $student = Student::query()->firstOrFail();
        $this->assertSame('active', $student->status);
        $this->assertMatchesRegularExpression('/^ALU-\d{6}-\d{6}$/', $student->registration_number);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'is_legal_guardian' => true]);
        $this->assertDatabaseHas('student_dojo_history', ['student_id' => $student->id, 'dojo_id' => $dojo->id, 'is_primary' => true]);
        $guardian = $student->guardians()->firstOrFail();
        $this->assertDatabaseHas('documents', ['student_guardian_id' => $guardian->id, 'person_id' => $guardian->guardian_person_id, 'student_id' => null]);
        Storage::disk('local')->assertExists($guardian->documents()->firstOrFail()->storage_path);
    }

    public function test_repeated_submission_is_idempotent(): void
    {
        [$association, $dojo, $art, $user] = $this->context(['student.manage']);
        $payload = $this->studentPayload($dojo, $art);

        $this->actingAs($user)->post(route('associations.students.direct.store', $association), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('associations.students.direct.store', $association), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('student_dojo_history', 1);
        $this->assertDatabaseCount('student_martial_arts', 1);
        $this->assertDatabaseCount('direct_registration_requests', 1);
    }

    public function test_existing_student_can_join_second_dojo_without_duplication_or_ending_first_link(): void
    {
        [$association, $dojoA, $art, $user] = $this->context(['student.manage']);
        $dojoB = Dojo::query()->create(['association_id' => $association->id, 'code' => 'B', 'name' => 'Dojo B']);
        $this->actingAs($user)->post(route('associations.students.direct.store', $association), $this->studentPayload($dojoA, $art))->assertSessionHasNoErrors();
        $student = Student::query()->firstOrFail();

        $this->post(route('associations.students.direct.store', $association), [
            ...$this->studentPayload($dojoB, $art),
            'person_mode' => 'existing',
            'person_id' => $student->person_id,
            'make_primary' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('students', 1);
        $this->assertSame(2, $student->dojoHistory()->count());
        $this->assertSame($dojoB->id, $student->fresh()->primary_dojo_id);
        $this->assertDatabaseHas('student_dojo_history', ['student_id' => $student->id, 'dojo_id' => $dojoA->id, 'end_date' => null]);
        $this->assertDatabaseHas('student_dojo_history', ['student_id' => $student->id, 'dojo_id' => $dojoB->id, 'is_primary' => true]);
    }

    public function test_cpf_from_another_association_is_not_revealed_or_linked(): void
    {
        [$association, $dojo, $art, $user] = $this->context(['student.manage']);
        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $external = Person::query()->create(['full_name' => 'Pessoa externa', 'cpf' => '52998224725']);
        $other->people()->attach($external, ['role_type' => 'student', 'start_date' => today(), 'status' => 'active']);
        $payload = $this->studentPayload($dojo, $art);
        $payload['person']['cpf'] = '529.982.247-25';
        $payload['confirm_existing_person'] = '1';

        $this->actingAs($user)->from(route('associations.students.create', $association))
            ->post(route('associations.students.direct.store', $association), $payload)
            ->assertSessionHasErrors('person.cpf')->assertSessionDoesntHaveErrors(['person.full_name']);

        $this->assertDatabaseCount('students', 0);
        $this->assertFalse($association->people()->whereKey($external->id)->exists());
    }

    public function test_dojo_operator_registers_only_in_granted_dojo(): void
    {
        [$association, $dojoA, $art] = $this->context([]);
        $dojoB = Dojo::query()->create(['association_id' => $association->id, 'code' => 'B', 'name' => 'Dojo B']);
        $operator = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'dojo', ['dojo.access', 'student.view', 'student.manage']);
        DojoRoleAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $dojoA->id, 'user_id' => $operator->id, 'role_id' => $role->id, 'start_date' => today(), 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)->get(route('operation.students.direct.create', $dojoA))->assertOk()->assertSee('Novo aluno');
        $this->get(route('operation.students.direct.create', $dojoB))->assertForbidden();
        $this->post(route('operation.students.direct.store', $dojoB), $this->studentPayload($dojoB, $art))->assertForbidden();
        $type = DocumentType::query()->create(['association_id' => $association->id, 'name' => 'Privado']);
        $forgedDocument = [
            ...$this->studentPayload($dojoA, $art),
            'document_owner' => 'student',
            'document_type_id' => $type->id,
            'document_file' => UploadedFile::fake()->createWithContent('arquivo.pdf', "%PDF-1.4\nSIGAM"),
        ];
        $this->post(route('operation.students.direct.store', $dojoA), $forgedDocument)->assertForbidden();
        $this->assertDatabaseCount('students', 0);
        $this->post(route('operation.students.direct.store', $dojoA), $this->studentPayload($dojoA, $art))->assertSessionHasNoErrors();

        $student = Student::query()->firstOrFail();
        $this->assertDatabaseHas('student_dojo_history', ['student_id' => $student->id, 'dojo_id' => $dojoA->id]);
        $this->assertDatabaseMissing('student_dojo_history', ['student_id' => $student->id, 'dojo_id' => $dojoB->id]);
    }

    public function test_manager_registers_teacher_directly_with_institutional_membership_and_document(): void
    {
        Storage::fake('local');
        [$association, , $art, $user] = $this->context(['teacher.manage', 'document.manage']);
        $type = DocumentType::query()->create(['association_id' => $association->id, 'name' => 'Certificado']);
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'person_mode' => 'new',
            'person' => ['full_name' => 'Professora Direta', 'email' => 'PROFESSORA@EXAMPLE.COM'],
            'martial_art_id' => $art->id,
            'document_type_id' => $type->id,
            'document_file' => UploadedFile::fake()->createWithContent('certificado.pdf', "%PDF-1.4\nSIGAM"),
        ];

        $this->actingAs($user)->post(route('associations.teachers.direct.store', $association), $payload)->assertSessionHasNoErrors();
        $this->post(route('associations.teachers.direct.store', $association), $payload)->assertSessionHasNoErrors();

        $teacher = TeacherProfile::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^PROF-\d{6}-\d{6}$/', $teacher->registration_number);
        $this->assertSame('professora@example.com', $teacher->person->email);
        $this->assertDatabaseHas('teacher_association_memberships', ['association_id' => $association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $this->assertDatabaseHas('documents', ['teacher_profile_id' => $teacher->id, 'person_id' => $teacher->person_id]);
        $this->assertDatabaseCount('teacher_profiles', 1);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_minor_cannot_be_activated_without_legal_guardian_but_can_be_saved_as_pre_registration(): void
    {
        [$association, $dojo, $art, $user] = $this->context(['student.manage']);
        $payload = $this->studentPayload($dojo, $art);
        $payload['person']['birth_date'] = '2016-05-10';
        $payload['finish_action'] = 'activate';

        $this->actingAs($user)->post(route('associations.students.direct.store', $association), $payload)->assertSessionHasErrors('guardian_is_legal');
        $this->assertDatabaseCount('students', 0);
        $payload['finish_action'] = 'pre_registration';
        $this->post(route('associations.students.direct.store', $association), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['association_id' => $association->id, 'status' => 'pre_registration']);
    }

    public function test_validation_messages_are_translated_and_shown_next_to_guardian_field(): void
    {
        [$association, $dojo, $art, $user] = $this->context(['student.manage']);
        $payload = $this->studentPayload($dojo, $art);
        $payload['guardian_mode'] = 'new';
        $payload['guardian'] = ['full_name' => 'Responsável Teste'];
        unset($payload['entry_date']);

        $this->actingAs($user)->from(route('associations.students.create', $association))
            ->post(route('associations.students.direct.store', $association), $payload)
            ->assertSessionHasErrors([
                'guardian_relationship' => 'Informe a relação do responsável com o aluno.',
                'entry_date' => 'O campo data de ingresso é obrigatório.',
            ]);

        $this->get(route('associations.students.create', $association))
            ->assertSee('Informe a relação do responsável com o aluno.')
            ->assertDontSee('validation.')
            ->assertSee('data-show-when="guardian_mode:new,existing"', false);
    }

    public function test_foreign_document_type_is_rejected_before_creating_student_or_file(): void
    {
        Storage::fake('local');
        [$association, $dojo, $art, $user] = $this->context(['student.manage', 'document.manage']);
        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $externalType = DocumentType::query()->create(['association_id' => $other->id, 'name' => 'Externo']);
        $payload = [
            ...$this->studentPayload($dojo, $art),
            'document_owner' => 'student',
            'document_type_id' => $externalType->id,
            'document_file' => UploadedFile::fake()->createWithContent('arquivo.pdf', "%PDF-1.4\nSIGAM"),
        ];

        $this->actingAs($user)->post(route('associations.students.direct.store', $association), $payload)
            ->assertSessionHasErrors('document_type_id');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('documents', 0);
        Storage::disk('local')->assertMissing('documents');
    }

    private function studentPayload(Dojo $dojo, MartialArt $art): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'person_mode' => 'new',
            'person' => ['full_name' => 'Aluno '.Str::random(6), 'birth_date' => '2000-01-01'],
            'dojo_id' => $dojo->id,
            'entry_date' => today()->toDateString(),
            'martial_art_id' => $art->id,
            'guardian_mode' => 'none',
            'finish_action' => 'pre_registration',
        ];
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'A', 'name' => 'Dojo A']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);
        if ($codes === []) {
            return [$association, $dojo, $art, null];
        }
        $user = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, 'association', $codes);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $dojo, $art, $user];
    }

    private function role(Association $association, string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Perfil', 'code' => $scope.'_'.Str::random(8), 'scope_type' => $scope]);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id);
        $role->permissions()->sync($permissions);

        return $role;
    }
}
