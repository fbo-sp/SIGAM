<?php

namespace App\Console\Commands;

use App\Services\Finance\RecurringChargeService;
use App\Services\Operations\JobRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateRecurringCharges extends Command
{
    protected $signature = 'sigam:charges:generate {--competence=} {--association=}';

    protected $description = 'Gera cobranças recorrentes da competência, sem duplicação.';

    public function handle(RecurringChargeService $service, JobRunRecorder $recorder): int
    {
        $input = $this->option('competence') ?: today()->format('Y-m');
        $competence = CarbonImmutable::createFromFormat('!Y-m', $input);
        if (! $competence || $competence->format('Y-m') !== $input) {
            $this->error('Informe a competência no formato AAAA-MM.');

            return self::FAILURE;
        }
        $count = $recorder->run('GenerateRecurringCharges', fn () => $service->generate($competence, $this->option('association') ? (int) $this->option('association') : null));
        $this->info("{$count} cobrança(s) criada(s).");

        return self::SUCCESS;
    }
}
