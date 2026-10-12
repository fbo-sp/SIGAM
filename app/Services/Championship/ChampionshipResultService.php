<?php

namespace App\Services\Championship;

use App\Models\Association;
use App\Models\ChampionshipRegistration;
use App\Models\ChampionshipResult;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChampionshipResultService
{
    public function __construct(private readonly AuditService $audit) {}

    public function record(Association $association, ChampionshipRegistration $registration, User $user, array $data): ChampionshipResult
    {
        return DB::transaction(function () use ($association, $registration, $user, $data): ChampionshipResult {
            $locked = ChampionshipRegistration::query()->where('association_id', $association->id)->with(['championship', 'charge', 'result'])->lockForUpdate()->find($registration->id);
            if (! $locked) {
                abort(404);
            }
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['result' => 'Somente inscrições aprovadas recebem resultado.']);
            }
            if ($locked->championship->start_date->gt(today())) {
                throw ValidationException::withMessages(['result' => 'O resultado só pode ser registrado a partir da data inicial do campeonato.']);
            }
            if ($data['participation_confirmed'] && $locked->charge && $locked->charge->status !== 'paid') {
                throw ValidationException::withMessages(['participation_confirmed' => 'A taxa da inscrição precisa estar totalmente paga para confirmar a participação.']);
            }
            if ($locked->participation_type !== 'competitor' && (! empty($data['placement']) || ! empty($data['medal']) || $data['walkover'] || $data['disqualified'])) {
                throw ValidationException::withMessages(['placement' => 'Colocação, medalha, WO e desclassificação são exclusivos de competidores.']);
            }
            if ($data['disqualified'] && (! empty($data['placement']) || ! empty($data['medal']))) {
                throw ValidationException::withMessages(['disqualified' => 'Participante desclassificado não pode receber colocação ou medalha.']);
            }
            $old = $locked->result?->toArray();
            $result = ChampionshipResult::query()->updateOrCreate(['championship_registration_id' => $locked->id], [...$data, 'recorded_by' => $user->id]);
            $this->audit->record('championship_result.recorded', $old ? 'update' : 'create', $result, $old, ['participation_confirmed' => $result->participation_confirmed, 'placement' => $result->placement, 'medal' => $result->medal, 'points' => $result->points], ['association_id' => $association->id, 'championship_id' => $locked->championship_id, 'registration_id' => $locked->id]);

            return $result;
        }, 3);
    }
}
