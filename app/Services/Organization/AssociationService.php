<?php

namespace App\Services\Organization;

use App\Models\Association;
use App\Services\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AssociationService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data): Association
    {
        return DB::transaction(function () use ($data): Association {
            $association = Association::query()->create($data);
            $this->audit->record('association.created', 'created', $association, null, Arr::only($association->toArray(), ['legal_name', 'trade_name', 'status']));

            return $association;
        });
    }

    public function update(Association $association, array $data): Association
    {
        return DB::transaction(function () use ($association, $data): Association {
            $before = Arr::only($association->toArray(), ['legal_name', 'trade_name', 'status']);
            $association->update($data);
            $after = Arr::only($association->fresh()->toArray(), ['legal_name', 'trade_name', 'status']);
            $this->audit->record('association.updated', 'updated', $association, $before, $after);

            return $association;
        });
    }
}
