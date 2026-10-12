<?php

namespace App\Services\Finance;

use App\Models\Charge;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OverdueChargeService
{
    public function __construct(private readonly AuditService $audit) {}

    public function mark(CarbonImmutable $referenceDate, ?int $associationId = null): int
    {
        $count = 0;
        Charge::query()->where('status', 'open')->whereDate('due_date', '<', $referenceDate)->when($associationId, fn ($query) => $query->where('association_id', $associationId))->orderBy('id')->chunkById(100, function ($charges) use (&$count): void {
            foreach ($charges as $charge) {
                DB::transaction(function () use ($charge, &$count): void {
                    $locked = Charge::query()->lockForUpdate()->findOrFail($charge->id);
                    if ($locked->status !== 'open') {
                        return;
                    }
                    $locked->update(['status' => 'overdue']);
                    $this->audit->record('charge.marked_overdue', 'update', $locked, ['status' => 'open'], ['status' => 'overdue']);
                    $count++;
                });
            }
        });

        return $count;
    }
}
