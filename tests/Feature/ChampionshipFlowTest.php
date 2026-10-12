<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentMartialArt;
use App\Models\StudentRankHistory;
use App\Models\User;
use App\Services\Championship\ChampionshipRegistrationService;
use App\Services\Championship\ChampionshipResultService;
use App\Services\Championship\ChampionshipService;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChampionshipFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_unique_and_freezes_server_calculated_data(): void
    {
        [$association, $championship, $student, $rank, $user] = $this->context('75.00');
        $service = app(ChampionshipRegistrationService::class);
        $registration = $service->register($association, $championship, $user, $this->competitorData($student, $rank));
        $championship->update(['default_fee' => '150.00']);

        $this->assertSame('75.00', $registration->registration_fee);
        $this->assertSame(26, $registration->age_at_event);
        $this->assertSame('pending', $registration->status);

        $this->expectException(ValidationException::class);
        $service->register($association, $championship, $user, $this->competitorData($student, $rank));
    }

    public function test_approval_generates_one_charge_in_shared_financial_engine(): void
    {
        [$association, $championship, $student, $rank, $user] = $this->context('75.00');
        $service = app(ChampionshipRegistrationService::class);
        $registration = $service->register($association, $championship, $user, $this->competitorData($student, $rank));
        $approved = $service->decide($association, $registration, $user, 'approve', null);

        $this->assertSame('approved', $approved->status);
        $this->assertNotNull($approved->charge_id);
        $this->assertDatabaseHas('charges', ['id' => $approved->charge_id, 'source_type' => 'championship_registration', 'source_id' => $registration->id, 'final_amount' => '75.00']);

        try {
            $service->decide($association, $registration, $user, 'approve', null);
            $this->fail('A decisão não pode ser repetida.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('charges', 1);
        }
    }

    public function test_paid_registration_result_appears_in_consolidated_sports_history(): void
    {
        [$association, $championship, $student, $rank, $user] = $this->context('75.00');
        $registrationService = app(ChampionshipRegistrationService::class);
        $registration = $registrationService->register($association, $championship, $user, $this->competitorData($student, $rank));
        $registration = $registrationService->decide($association, $registration, $user, 'approve', null);

        try {
            app(ChampionshipResultService::class)->record($association, $registration, $user, $this->resultData());
            $this->fail('Resultado futuro não pode ser registrado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('result', $exception->errors());
        }

        $this->travelTo($championship->start_date->startOfDay());
        try {
            app(ChampionshipResultService::class)->record($association, $registration, $user, $this->resultData());
            $this->fail('Participação com taxa em aberto não pode ser confirmada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('participation_confirmed', $exception->errors());
        }

        app(PaymentService::class)->post($association, $registration->charge, $user, ['idempotency_key' => (string) Str::uuid(), 'payment_date' => today()->format('Y-m-d'), 'amount' => '75.00', 'payment_method' => 'pix', 'reference' => 'CAMPEONATO', 'notes' => null]);
        app(ChampionshipResultService::class)->record($association, $registration, $user, $this->resultData());
        $this->travelBack();

        $this->assertDatabaseHas('championship_results', ['championship_registration_id' => $registration->id, 'participation_confirmed' => true, 'placement' => 1, 'medal' => 'gold', 'points' => '10.00']);
        $viewer = $this->user($association, ['student.view', 'championship.view']);
        $this->actingAs($viewer)->get(route('associations.students.show', [$association, $student]))->assertOk()->assertSee($championship->name)->assertSee('medalha ouro')->assertSee('10.00 ponto(s)');
    }

    public function test_deadline_role_scope_reports_and_free_registration_are_enforced(): void
    {
        [$association, $championship, $student, $rank, $user] = $this->context('0.00');
        $championship->update(['allow_assistant' => true]);
        $service = app(ChampionshipRegistrationService::class);
        $assistant = $service->register($association, $championship, $user, ['person_id' => $student->person_id, 'student_id' => $student->id, 'dojo_id' => $student->primary_dojo_id, 'federation_membership_id' => null, 'participation_type' => 'assistant', 'category' => null, 'weight' => null, 'rank_id' => null, 'team_name' => null, 'referee_level' => null, 'assistant_function' => 'Apoio de mesa', 'notes' => null]);
        $assistant = $service->decide($association, $assistant, $user, 'approve', null);
        $this->assertNull($assistant->charge_id);
        $this->travelTo($championship->start_date->startOfDay());
        app(ChampionshipResultService::class)->record($association, $assistant, $user, ['participation_confirmed' => true, 'placement' => null, 'medal' => null, 'points' => '2.00', 'walkover' => false, 'disqualified' => false, 'notes' => 'Apoio concluído.']);
        $this->travelBack();

        $viewer = $this->user($association, ['championship.view']);
        $this->actingAs($viewer)->get(route('associations.championships.show', [$association, $championship]))->assertOk()->assertSee('Apoio de mesa');
        $export = $this->get(route('associations.championships.export', [$association, $championship]));
        $this->assertStringContainsString('assistant', $export->streamedContent());

        $otherAssociation = Association::query()->create(['legal_name' => 'Outra associação', 'trade_name' => 'Outra']);
        $this->get(route('associations.championships.show', [$otherAssociation, $championship]))->assertForbidden();

        $championship->update(['registration_close_at' => now()->subMinute()]);
        $this->expectException(ValidationException::class);
        $service->register($association, $championship, $user, $this->competitorData($student, $rank));
    }

    private function context(string $fee): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.uniqid(), 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D'.uniqid(), 'name' => 'Dojo']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô '.uniqid(), 'status' => 'active']);
        $rank = Rank::query()->create(['martial_art_id' => $art->id, 'name' => 'branca', 'display_name' => 'Faixa branca', 'sort_order' => 1, 'status' => 'active']);
        $person = Person::query()->create(['full_name' => 'Competidor '.uniqid(), 'birth_date' => '2000-01-01']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'A'.uniqid(), 'entry_date' => '2026-01-01', 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
        $link = StudentMartialArt::query()->create(['student_id' => $student->id, 'martial_art_id' => $art->id, 'start_date' => '2026-01-01', 'status' => 'active', 'current_rank_id' => $rank->id]);
        StudentRankHistory::query()->create(['student_martial_art_id' => $link->id, 'rank_id' => $rank->id, 'achieved_at' => '2026-01-01', 'source_type' => 'initial_registration']);
        $user = User::factory()->create(['association_id' => $association->id]);
        $championship = app(ChampionshipService::class)->create($association, $user, ['dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Copa SIGAM '.uniqid(), 'description' => 'Evento de teste', 'championship_type' => 'internal', 'organizer' => 'Associação', 'location_name' => 'Ginásio', 'start_date' => today()->addMonth()->format('Y-m-d'), 'end_date' => today()->addMonth()->format('Y-m-d'), 'registration_open_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'registration_close_at' => now()->addWeek()->format('Y-m-d H:i:s'), 'rules_text' => null, 'default_fee' => $fee, 'allow_competitor' => true, 'allow_referee' => false, 'allow_assistant' => false, 'status' => 'open']);

        return [$association, $championship, $student, $rank, $user];
    }

    private function competitorData(Student $student, Rank $rank): array
    {
        return ['person_id' => $student->person_id, 'student_id' => $student->id, 'dojo_id' => $student->primary_dojo_id, 'federation_membership_id' => null, 'participation_type' => 'competitor', 'category' => 'Adulto -73kg', 'weight' => '72.50', 'rank_id' => $rank->id, 'team_name' => 'Equipe SIGAM', 'referee_level' => null, 'assistant_function' => null, 'notes' => null];
    }

    private function resultData(): array
    {
        return ['participation_confirmed' => true, 'placement' => 1, 'medal' => 'gold', 'points' => '10.00', 'walkover' => false, 'disqualified' => false, 'notes' => 'Campeão.'];
    }

    private function user(Association $association, array $codes): User
    {
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Campeonatos', 'code' => 'championship_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }
}
