<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Dojo;
use App\Models\Federation;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentMartialArt;
use App\Models\StudentRankHistory;
use App\Models\User;
use App\Services\Federation\FederationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FederationMembershipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_uses_recorded_rank_snapshot_without_changing_sports_history(): void
    {
        [$association, $student, $art, $rank, $federation, $user, $link] = $this->context();
        $membership = app(FederationMembershipService::class)->createMembership($association, $user, $this->membershipData($student, $art, $rank, $federation, 2026));
        $newRank = Rank::query()->create(['martial_art_id' => $art->id, 'name' => 'azul', 'display_name' => 'Faixa azul', 'sort_order' => 2, 'status' => 'active']);
        StudentRankHistory::query()->create(['student_martial_art_id' => $link->id, 'rank_id' => $newRank->id, 'achieved_at' => '2027-01-10', 'source_type' => 'manual']);
        $link->update(['current_rank_id' => $newRank->id]);

        $this->assertSame($rank->id, $membership->fresh()->rank_id);
        $this->assertSame('Faixa branca', $membership->rank->display_name);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'federation_membership.created', 'entity_id' => $membership->id]);
    }

    public function test_membership_period_is_immutable_and_cannot_be_duplicated(): void
    {
        [$association, $student, $art, $rank, $federation, $user] = $this->context();
        $service = app(FederationMembershipService::class);
        $service->createMembership($association, $user, $this->membershipData($student, $art, $rank, $federation, 2026));

        try {
            $service->createMembership($association, $user, $this->membershipData($student, $art, $rank, $federation, 2026));
            $this->fail('A mesma filiação anual não pode ser duplicada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference_year', $exception->errors());
        }

        $renewal = $service->createMembership($association, $user, $this->membershipData($student, $art, $rank, $federation, 2027));
        $this->assertSame(2027, $renewal->reference_year);
        $this->assertDatabaseCount('federation_memberships', 2);
    }

    public function test_rank_must_exist_in_student_consolidated_history(): void
    {
        [$association, $student, $art, , $federation, $user] = $this->context();
        $unrecorded = Rank::query()->create(['martial_art_id' => $art->id, 'name' => 'preta', 'display_name' => 'Faixa preta', 'sort_order' => 10, 'status' => 'active']);

        $this->expectException(ValidationException::class);
        app(FederationMembershipService::class)->createMembership($association, $user, $this->membershipData($student, $art, $unrecorded, $federation, 2026));
    }

    public function test_federation_pages_reports_documents_and_scope_are_protected(): void
    {
        Storage::fake('local');
        [$association, $student, $art, $rank, $federation, $creator] = $this->context();
        $membership = app(FederationMembershipService::class)->createMembership($association, $creator, $this->membershipData($student, $art, $rank, $federation, 2026));
        $type = DocumentType::query()->create(['association_id' => $association->id, 'name' => 'Carteira federativa', 'requires_expiration' => true]);
        $user = $this->user($association, ['federation.view', 'federation.manage', 'document.download']);

        $this->actingAs($user)->get(route('associations.federation.index', $association))->assertOk()->assertSee('Histórico federativo')->assertSee($student->person->full_name);
        $export = $this->get(route('associations.federation.memberships.export', [$association, 'reference_year' => 2026]));
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Faixa branca', $export->streamedContent());

        $this->post(route('associations.federation.memberships.documents.store', [$association, $membership]), ['student_id' => $student->id, 'document_type_id' => $type->id, 'file' => UploadedFile::fake()->createWithContent('carteira.pdf', "%PDF-1.4\nSIGAM"), 'expiration_date' => '2026-12-31'])->assertSessionHasNoErrors();
        $document = Document::query()->sole();
        $this->assertSame($membership->id, $document->federation_membership_id);
        Storage::disk('local')->assertExists($document->storage_path);

        [$otherAssociation] = $this->context();
        $this->get(route('associations.federation.memberships.show', [$otherAssociation, $membership]))->assertForbidden();
    }

    private function context(): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.uniqid(), 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D'.uniqid(), 'name' => 'Dojo']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô '.uniqid(), 'status' => 'active']);
        $rank = Rank::query()->create(['martial_art_id' => $art->id, 'name' => 'branca', 'display_name' => 'Faixa branca', 'sort_order' => 1, 'status' => 'active']);
        $person = Person::query()->create(['full_name' => 'Aluno '.uniqid(), 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'A'.uniqid(), 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $link = StudentMartialArt::query()->create(['student_id' => $student->id, 'martial_art_id' => $art->id, 'start_date' => '2026-01-01', 'status' => 'active', 'current_rank_id' => $rank->id]);
        StudentRankHistory::query()->create(['student_martial_art_id' => $link->id, 'rank_id' => $rank->id, 'achieved_at' => '2026-01-01', 'source_type' => 'initial_registration']);
        $federation = Federation::query()->create(['association_id' => $association->id, 'name' => 'Federação '.uniqid(), 'acronym' => 'FED'.uniqid(), 'federation_type' => 'state', 'state' => 'SP', 'country' => 'BR', 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $association->id]);

        return [$association, $student, $art, $rank, $federation, $user, $link];
    }

    private function membershipData(Student $student, MartialArt $art, Rank $rank, Federation $federation, int $year): array
    {
        return ['student_id' => $student->id, 'federation_id' => $federation->id, 'martial_art_id' => $art->id, 'rank_id' => $rank->id, 'registration_number' => 'REG-'.$year, 'reference_year' => $year, 'registration_date' => $year.'-01-10', 'expiration_date' => $year.'-12-31', 'points' => '12.50', 'status' => 'active', 'notes' => null];
    }

    private function user(Association $association, array $codes): User
    {
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Federações', 'code' => 'federation_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }
}
