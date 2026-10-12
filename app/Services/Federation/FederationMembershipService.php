<?php

namespace App\Services\Federation;

use App\Models\Association;
use App\Models\Federation;
use App\Models\FederationMembership;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Models\Student;
use App\Models\StudentRankHistory;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FederationMembershipService
{
    public function __construct(private readonly AuditService $audit) {}

    public function createFederation(Association $association, array $data): Federation
    {
        $federation = Federation::query()->create([...$data, 'association_id' => $association->id]);
        $this->audit->record('federation.created', 'create', $federation, null, ['acronym' => $federation->acronym, 'status' => $federation->status]);

        return $federation;
    }

    public function updateFederation(Association $association, Federation $federation, array $data): Federation
    {
        if ($federation->association_id !== $association->id) {
            abort(404);
        }
        $old = $federation->only(['name', 'acronym', 'federation_type', 'state', 'country', 'email', 'phone', 'website', 'status']);
        $federation->update($data);
        $this->audit->record('federation.updated', 'update', $federation, $old, $federation->only(array_keys($old)));

        return $federation->refresh();
    }

    public function createMembership(Association $association, User $user, array $data): FederationMembership
    {
        return DB::transaction(function () use ($association, $user, $data): FederationMembership {
            $student = Student::query()->where('association_id', $association->id)->lockForUpdate()->find($data['student_id']);
            $federation = Federation::query()->where('association_id', $association->id)->where('status', 'active')->find($data['federation_id']);
            $martialArt = MartialArt::query()->whereKey($data['martial_art_id'])->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->first();
            $rank = Rank::query()->whereKey($data['rank_id'])->where('martial_art_id', $data['martial_art_id'])->where('status', 'active')->first();
            if (! $student) {
                throw ValidationException::withMessages(['student_id' => 'Selecione um aluno desta associação.']);
            }
            if (! $federation) {
                throw ValidationException::withMessages(['federation_id' => 'Selecione uma federação ativa desta associação.']);
            }
            if (! $martialArt || ! $student->martialArts()->where('martial_art_id', $martialArt->id)->exists()) {
                throw ValidationException::withMessages(['martial_art_id' => 'Selecione uma modalidade praticada pelo aluno.']);
            }
            if (! $rank) {
                throw ValidationException::withMessages(['rank_id' => 'A graduação não pertence à modalidade selecionada.']);
            }
            $rankRecorded = StudentRankHistory::query()->where('rank_id', $rank->id)->whereHas('studentMartialArt', fn ($query) => $query->where('student_id', $student->id)->where('martial_art_id', $martialArt->id))->exists();
            if (! $rankRecorded) {
                throw ValidationException::withMessages(['rank_id' => 'A graduação precisa constar no histórico esportivo do aluno.']);
            }
            if ((int) $data['reference_year'] !== (int) substr($data['registration_date'], 0, 4)) {
                throw ValidationException::withMessages(['reference_year' => 'O ano de referência deve corresponder à data de inscrição.']);
            }

            $exists = FederationMembership::query()->where('student_id', $student->id)->where('federation_id', $federation->id)->where('martial_art_id', $martialArt->id)->where('reference_year', $data['reference_year'])->exists();
            if ($exists) {
                throw ValidationException::withMessages(['reference_year' => 'Já existe uma filiação deste aluno, federação e modalidade para o ano informado.']);
            }

            $membership = FederationMembership::query()->create([...$data, 'association_id' => $association->id, 'created_by' => $user->id]);
            $this->audit->record('federation_membership.created', 'create', $membership, null, ['federation_id' => $federation->id, 'student_id' => $student->id, 'martial_art_id' => $martialArt->id, 'rank_id' => $rank->id, 'reference_year' => $membership->reference_year]);

            return $membership;
        }, 3);
    }
}
