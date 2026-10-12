<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_cannot_be_updated_through_the_model(): void
    {
        $log = AuditLog::query()->create([
            'event_type' => 'test.created',
            'action' => 'created',
        ]);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'changed']);
    }

    public function test_audit_log_cannot_be_deleted_through_the_model(): void
    {
        $log = AuditLog::query()->create([
            'event_type' => 'test.created',
            'action' => 'created',
        ]);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
