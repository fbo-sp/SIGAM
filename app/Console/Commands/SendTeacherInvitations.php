<?php

namespace App\Console\Commands;

use App\Models\ScheduledJobRun;
use App\Services\Access\TeacherInvitationDeliveryService;
use Illuminate\Console\Command;

class SendTeacherInvitations extends Command
{
    protected $signature = 'sigam:teacher-invitations:send {--limit=50}';

    protected $description = 'Envia convites de professor com reserva exclusiva e retentativa limitada';

    public function handle(TeacherInvitationDeliveryService $delivery): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Informe um limite entre 1 e 500.');

            return self::INVALID;
        }
        if (! app()->runningUnitTests() && config('mail.default') !== 'smtp') {
            $this->error('Envio desabilitado: configure SMTP antes de consumir convites.');

            return self::FAILURE;
        }
        $run = ScheduledJobRun::query()->create(['job_name' => 'SendTeacherInvitations', 'started_at' => now(), 'status' => 'running']);
        try {
            $result = $delivery->sendBatch($limit);
            $run->update(['finished_at' => now(), 'status' => $result['failed'] ? 'failed' : 'completed', 'processed_count' => $result['processed'], 'error_count' => $result['failed'], 'message' => 'Lote de convites finalizado.']);
            $this->info($result['processed'].' convite(s), '.$result['failed'].' falha(s).');

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $run->update(['finished_at' => now(), 'status' => 'failed', 'error_count' => 1, 'message' => 'Processamento interrompido; confira convites em sending.']);
            $this->error('Falha operacional. Consulte o runbook.');

            return self::FAILURE;
        }
    }
}
