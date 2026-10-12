<?php

namespace App\Services\Organization;

use App\Models\Association;
use App\Models\Dojo;
use App\Services\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DojoService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(Association $association, array $data): Dojo
    {
        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association' => 'Ative a associação antes de cadastrar um novo dojo.']);
        }

        return DB::transaction(function () use ($association, $data): Dojo {
            $dojo = $association->dojos()->create($data);
            $this->audit->record('dojo.created', 'created', $dojo, null, Arr::only($dojo->toArray(), ['code', 'name', 'status']));

            return $dojo;
        });
    }

    public function update(Dojo $dojo, array $data): Dojo
    {
        return DB::transaction(function () use ($dojo, $data): Dojo {
            $before = Arr::only($dojo->toArray(), ['code', 'name', 'status']);
            $dojo->update($data);
            $after = Arr::only($dojo->fresh()->toArray(), ['code', 'name', 'status']);
            $this->audit->record('dojo.updated', 'updated', $dojo, $before, $after);

            return $dojo;
        });
    }
}
