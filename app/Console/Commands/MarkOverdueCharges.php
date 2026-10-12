<?php

namespace App\Console\Commands;

use App\Services\Finance\OverdueChargeService;
use App\Services\Operations\JobRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkOverdueCharges extends Command
{
    protected $signature = 'sigam:charges:mark-overdue {--date=} {--association=}';

    protected $description = 'Marca como vencidas apenas cobranças abertas anteriores à data de referência.';

    public function handle(OverdueChargeService $service, JobRunRecorder $recorder): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?: today())->startOfDay();
        $count = $recorder->run('MarkOverdueCharges', fn () => $service->mark($date, $this->option('association') ? (int) $this->option('association') : null));
        $this->info("{$count} cobrança(s) marcada(s) como vencida(s).");

        return self::SUCCESS;
    }
}
