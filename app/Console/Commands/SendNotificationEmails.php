<?php

namespace App\Console\Commands;

use App\Models\ScheduledJobRun;
use App\Services\Notifications\AnnouncementEmailService;
use App\Services\Notifications\EmailDeliveryService;
use Illuminate\Console\Command;

class SendNotificationEmails extends Command
{
    protected $signature = 'sigam:notifications:send {--limit=50}';

    protected $description = 'Processa entregas com reserva exclusiva e falhas sem dados sensíveis';

    public function handle(EmailDeliveryService $delivery, AnnouncementEmailService $announcements): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Informe um limite entre 1 e 500.');

            return self::INVALID;
        }
        // Log/array mailers must never be mistaken for real delivery outside tests.
        if (! app()->runningUnitTests() && config('mail.default') !== 'smtp') {
            $this->error('Envio desabilitado: configure SMTP antes de consumir a fila.');

            return self::FAILURE;
        }
        $run = ScheduledJobRun::query()->create(['job_name' => 'SendNotificationEmails', 'started_at' => now(), 'status' => 'running']);
        try {
            $result = $delivery->sendBatch($limit);
            // Avisos escritos por pessoas usam o que sobrou do lote.
            if ($result['processed'] < $limit) {
                $more = $announcements->sendBatch($limit - $result['processed']);
                $result = ['processed' => $result['processed'] + $more['processed'], 'failed' => $result['failed'] + $more['failed']];
            }
            $run->update(['finished_at' => now(), 'status' => $result['failed'] ? 'failed' : 'completed', 'processed_count' => $result['processed'], 'error_count' => $result['failed'], 'message' => 'Lote finalizado. Consulte os estados das entregas.']);
            $this->info($result['processed'].' entrega(s), '.$result['failed'].' falha(s).');

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $run->update(['finished_at' => now(), 'status' => 'failed', 'error_count' => 1, 'message' => 'Processamento interrompido; confira entregas em sending antes de retomar.']);
            $this->error('Falha operacional. Consulte o runbook.');

            return self::FAILURE;
        }
    }
}
