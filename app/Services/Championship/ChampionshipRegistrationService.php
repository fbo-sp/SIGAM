<?php

namespace App\Services\Championship;

use App\Models\Association;
use App\Models\Championship;
use App\Models\ChampionshipDojoParticipation;
use App\Models\ChampionshipRegistration;
use App\Models\Charge;
use App\Models\Dojo;
use App\Models\FederationMembership;
use App\Models\Person;
use App\Models\Rank;
use App\Models\Student;
use App\Models\StudentRankHistory;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChampionshipRegistrationService
{
    public function __construct(private readonly AuditService $audit) {}

    public function registerForDojo(Association $association, Dojo $dojo, Championship $championship, User $user, array $data): ChampionshipRegistration
    {
        return DB::transaction(function () use ($association, $dojo, $championship, $user, $data): ChampionshipRegistration {
            if ($dojo->association_id !== $association->id || $championship->association_id !== $association->id) {
                abort(404);
            }
            $student = Student::query()->where('association_id', $association->id)->where('status', 'active')
                ->whereHas('dojoHistory', fn ($query) => $query->where('dojo_id', $dojo->id)->whereDate('start_date', '<=', today())
                    ->where(fn ($period) => $period->whereNull('end_date')->orWhereDate('end_date', '>=', today())))
                ->whereHas('martialArts', fn ($query) => $query->where('martial_art_id', $championship->martial_art_id)->where('status', 'active'))
                ->with(['martialArts' => fn ($query) => $query->where('martial_art_id', $championship->martial_art_id)->where('status', 'active')])
                ->lockForUpdate()->find($data['student_id']);
            if (! $student) {
                throw ValidationException::withMessages(['student_id' => 'Selecione um aluno deste dojo que pratique a modalidade do campeonato.']);
            }
            $rankId = $student->martialArts->first()?->current_rank_id;
            if (! $rankId) {
                throw ValidationException::withMessages(['student_id' => 'Atualize a graduação do aluno antes de fazer a inscrição.']);
            }

            $registration = $this->register($association, $championship, $user, [
                ...$data,
                'person_id' => $student->person_id,
                'student_id' => $student->id,
                'dojo_id' => $dojo->id,
                'participation_type' => 'competitor',
                'rank_id' => $rankId,
                'referee_level' => null,
                'assistant_function' => null,
            ]);
            ChampionshipDojoParticipation::query()->firstOrCreate(
                ['championship_id' => $championship->id, 'dojo_id' => $dojo->id],
                ['status' => 'active', 'joined_at' => now(), 'created_by' => $user->id],
            );

            return $registration;
        }, 3);
    }

    public function register(Association $association, Championship $championship, User $user, array $data): ChampionshipRegistration
    {
        return DB::transaction(function () use ($association, $championship, $user, $data): ChampionshipRegistration {
            $locked = Championship::query()->where('association_id', $association->id)->lockForUpdate()->find($championship->id);
            if (! $locked) {
                abort(404);
            }
            if (! $locked->acceptsRegistrations()) {
                throw ValidationException::withMessages(['registration' => 'As inscrições não estão abertas para este campeonato.']);
            }
            $role = $data['participation_type'];
            if (! $locked->{'allow_'.$role}) {
                throw ValidationException::withMessages(['participation_type' => 'O campeonato não aceita esta forma de participação.']);
            }
            $person = Person::query()->find($data['person_id']);
            if (! $person || ! $association->people()->whereKey($person->id)->exists()) {
                throw ValidationException::withMessages(['person_id' => 'Selecione uma pessoa desta associação.']);
            }
            $student = ! empty($data['student_id']) ? Student::query()->where('association_id', $association->id)->find($data['student_id']) : null;
            if ($student && $student->person_id !== $person->id) {
                throw ValidationException::withMessages(['student_id' => 'O aluno deve representar a pessoa selecionada.']);
            }
            $dojo = ! empty($data['dojo_id']) ? Dojo::query()->where('association_id', $association->id)->find($data['dojo_id']) : null;
            if (! empty($data['dojo_id']) && ! $dojo) {
                throw ValidationException::withMessages(['dojo_id' => 'Selecione um dojo desta associação.']);
            }

            $age = null;
            $rank = null;
            if ($role === 'competitor') {
                if (! $student) {
                    throw ValidationException::withMessages(['student_id' => 'Competidor precisa estar vinculado a um aluno.']);
                }
                if (empty($data['category']) || empty($data['weight']) || empty($data['rank_id'])) {
                    throw ValidationException::withMessages(['category' => 'Informe categoria, peso e graduação do competidor.']);
                }
                if (! $person->birth_date) {
                    throw ValidationException::withMessages(['person_id' => 'Informe a data de nascimento da pessoa antes da inscrição.']);
                }
                $age = (int) floor($person->birth_date->diffInYears($locked->start_date));
                $rank = Rank::query()->whereKey($data['rank_id'])->where('martial_art_id', $locked->martial_art_id)->first();
                $rankRecorded = $rank && StudentRankHistory::query()->where('rank_id', $rank->id)->whereHas('studentMartialArt', fn ($query) => $query->where('student_id', $student->id)->where('martial_art_id', $locked->martial_art_id))->exists();
                if (! $rankRecorded) {
                    throw ValidationException::withMessages(['rank_id' => 'A graduação precisa constar no histórico do aluno nesta modalidade.']);
                }
            }
            if ($role === 'assistant' && empty($data['assistant_function'])) {
                throw ValidationException::withMessages(['assistant_function' => 'Informe a função do auxiliar.']);
            }

            $membership = ! empty($data['federation_membership_id']) ? FederationMembership::query()->where('association_id', $association->id)->find($data['federation_membership_id']) : null;
            if (! empty($data['federation_membership_id']) && (! $membership || $membership->student?->person_id !== $person->id || $membership->martial_art_id !== $locked->martial_art_id)) {
                throw ValidationException::withMessages(['federation_membership_id' => 'A filiação não corresponde à pessoa e modalidade do campeonato.']);
            }
            if ($membership && ($membership->status !== 'active' || ($membership->expiration_date && $membership->expiration_date->lt($locked->start_date)))) {
                throw ValidationException::withMessages(['federation_membership_id' => 'A filiação precisa estar válida na data do campeonato.']);
            }
            if (ChampionshipRegistration::query()->where('championship_id', $locked->id)->where('person_id', $person->id)->where('participation_type', $role)->exists()) {
                throw ValidationException::withMessages(['participation_type' => 'Esta pessoa já está inscrita neste papel.']);
            }

            $registration = ChampionshipRegistration::query()->create([...$data, 'association_id' => $association->id, 'championship_id' => $locked->id, 'age_at_event' => $age, 'registration_fee' => $locked->default_fee, 'status' => 'pending', 'created_by' => $user->id]);
            $this->audit->record('championship_registration.created', 'create', $registration, null, ['championship_id' => $locked->id, 'person_id' => $person->id, 'participation_type' => $role, 'registration_fee' => $registration->registration_fee]);

            return $registration;
        }, 3);
    }

    public function decide(Association $association, ChampionshipRegistration $registration, User $user, string $decision, ?string $reason): ChampionshipRegistration
    {
        return DB::transaction(function () use ($association, $registration, $user, $decision, $reason): ChampionshipRegistration {
            $locked = ChampionshipRegistration::query()->where('association_id', $association->id)->lockForUpdate()->find($registration->id);
            if (! $locked) {
                abort(404);
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Esta inscrição já recebeu uma decisão.']);
            }
            if ($locked->championship()->where('status', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['decision' => 'Não é possível decidir inscrições de um campeonato cancelado.']);
            }
            if ($decision === 'reject') {
                $locked->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_reason' => $reason]);
                $this->audit->record('championship_registration.rejected', 'update', $locked, ['status' => 'pending'], ['status' => 'rejected'], ['championship_id' => $locked->championship_id]);

                return $locked->refresh();
            }

            $charge = null;
            if (bccomp($locked->registration_fee, '0.00', 2) === 1) {
                $charge = Charge::query()->where('source_type', 'championship_registration')->where('source_id', $locked->id)->first();
                if (! $charge) {
                    $locked->load(['championship', 'person']);
                    $event = CarbonImmutable::parse($locked->championship->start_date);
                    $close = CarbonImmutable::parse($locked->championship->registration_close_at)->startOfDay();
                    $due = $close->gte(today()) ? $close : ($event->gte(today()) ? $event : CarbonImmutable::today());
                    $charge = Charge::query()->create(['association_id' => $association->id, 'dojo_id' => $locked->dojo_id, 'student_id' => $locked->student_id, 'person_id' => $locked->person_id, 'source_type' => 'championship_registration', 'source_id' => $locked->id, 'description' => 'Inscrição — '.$locked->championship->name.' ('.match ($locked->participation_type) {
                        'competitor' => 'competidor','referee' => 'árbitro',default => 'auxiliar'
                    }.')', 'competence' => $event->startOfMonth(), 'issued_at' => now(), 'due_date' => $due, 'original_amount' => $locked->registration_fee, 'discount_amount' => '0.00', 'final_amount' => $locked->registration_fee, 'paid_amount' => '0.00', 'balance_amount' => $locked->registration_fee, 'calculation_snapshot' => ['version' => 1, 'source' => 'championship_registration', 'championship_id' => $locked->championship_id, 'participation_type' => $locked->participation_type, 'fee' => $locked->registration_fee, 'explanation' => ['Taxa congelada no momento da inscrição.']], 'due_policy_snapshot' => ['source' => 'championship_registration_close', 'weekend_policy' => 'none'], 'status' => 'open', 'created_by' => $user->id]);
                    $this->audit->record('charge.generated', 'create', $charge, null, ['source_type' => 'championship_registration', 'source_id' => $locked->id, 'final_amount' => $charge->final_amount, 'status' => 'open']);
                }
            }
            $locked->update(['status' => 'approved', 'charge_id' => $charge?->id, 'approved_by' => $user->id, 'approved_at' => now(), 'decision_reason' => $reason]);
            $this->audit->record('championship_registration.approved', 'update', $locked, ['status' => 'pending'], ['status' => 'approved', 'charge_id' => $charge?->id], ['championship_id' => $locked->championship_id]);

            return $locked->refresh();
        }, 3);
    }
}
