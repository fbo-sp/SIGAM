<?php

namespace App\Services\Championship;

use App\Models\Association;
use App\Models\Championship;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Validation\ValidationException;

class ChampionshipService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(Association $association, User $user, array $data): Championship
    {
        $this->validateScope($association, $data);
        if (! $data['allow_competitor'] && ! $data['allow_referee'] && ! $data['allow_assistant']) {
            throw ValidationException::withMessages(['allow_competitor' => 'Selecione pelo menos uma forma de participação.']);
        }
        $championship = Championship::query()->create([...$data, 'association_id' => $association->id, 'created_by' => $user->id]);
        $this->audit->record('championship.created', 'create', $championship, null, ['martial_art_id' => $championship->martial_art_id, 'start_date' => $championship->start_date->format('Y-m-d'), 'default_fee' => $championship->default_fee, 'status' => $championship->status]);

        return $championship;
    }

    public function update(Association $association, Championship $championship, array $data): Championship
    {
        if ($championship->association_id !== $association->id) {
            abort(404);
        }
        $this->validateScope($association, $data);
        if (! $data['allow_competitor'] && ! $data['allow_referee'] && ! $data['allow_assistant']) {
            throw ValidationException::withMessages(['allow_competitor' => 'Selecione pelo menos uma forma de participação.']);
        }
        if ($championship->registrations()->exists()) {
            $frozen = ['dojo_id', 'martial_art_id', 'championship_type', 'start_date', 'end_date', 'registration_open_at', 'registration_close_at', 'default_fee', 'allow_competitor', 'allow_referee', 'allow_assistant'];
            foreach ($frozen as $field) {
                $current = $championship->{$field};
                if ($current instanceof \DateTimeInterface) {
                    $current = $current->format(str_contains($field, '_at') ? 'Y-m-d H:i:s' : 'Y-m-d');
                }
                $incoming = in_array($field, ['allow_competitor', 'allow_referee', 'allow_assistant'], true) ? (bool) $data[$field] : (string) ($data[$field] ?? '');
                if ((string) $current !== (string) $incoming) {
                    throw ValidationException::withMessages([$field => 'Este campo não pode mudar depois da primeira inscrição.']);
                }
            }
        }
        $old = $championship->only(['name', 'status', 'start_date', 'end_date', 'default_fee']);
        $championship->update($data);
        $this->audit->record('championship.updated', 'update', $championship, $old, $championship->only(array_keys($old)));

        return $championship->refresh();
    }

    private function validateScope(Association $association, array $data): void
    {
        if (! empty($data['dojo_id']) && ! Dojo::query()->where('association_id', $association->id)->whereKey($data['dojo_id'])->exists()) {
            throw ValidationException::withMessages(['dojo_id' => 'Selecione um dojo desta associação.']);
        }
        if (! MartialArt::query()->whereKey($data['martial_art_id'])->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->exists()) {
            throw ValidationException::withMessages(['martial_art_id' => 'Selecione uma modalidade disponível nesta associação.']);
        }
    }
}
