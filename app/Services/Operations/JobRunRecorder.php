<?php

namespace App\Services\Operations;

use App\Models\ScheduledJobRun;
use Throwable;

class JobRunRecorder
{
    public function run(string $jobName, callable $operation): int
    {
        $run = ScheduledJobRun::query()->create(['job_name' => $jobName, 'started_at' => now(), 'status' => 'running']);
        try {
            $processed = (int) $operation();
            $run->update(['finished_at' => now(), 'status' => 'completed', 'processed_count' => $processed, 'message' => 'Execução concluída.']);

            return $processed;
        } catch (Throwable $exception) {
            $run->update(['finished_at' => now(), 'status' => 'failed', 'error_count' => 1, 'message' => 'Falha operacional. Consulte o runbook; detalhes sensíveis foram omitidos.']);
            throw $exception;
        }
    }
}
