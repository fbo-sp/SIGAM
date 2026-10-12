<?php

namespace App\Services;

use App\Models\Association;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function record(
        string $eventType,
        string $action,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $context = null,
    ): AuditLog {
        $request = request();
        $user = Auth::user();
        $associationId = $context['association_id'] ?? match (true) {
            $entity instanceof Association => $entity->getKey(),
            $entity !== null && isset($entity->association_id) => $entity->association_id,
            default => $user?->association_id,
        };

        return AuditLog::query()->create([
            'association_id' => $associationId,
            'user_id' => $user?->getAuthIdentifier(),
            'event_type' => $eventType,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'context' => $context,
        ]);
    }
}
