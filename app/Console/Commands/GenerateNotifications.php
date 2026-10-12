<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationGenerator;
use App\Services\Operations\JobRunRecorder;
use Illuminate\Console\Command;

class GenerateNotifications extends Command
{
    protected $signature = 'sigam:notifications:generate {--association=}';

    protected $description = 'Gera notificações internas e entregas pendentes de forma idempotente';

    public function handle(NotificationGenerator $generator, JobRunRecorder $runs): int
    {
        $count = $runs->run('GenerateNotifications', fn () => $generator->generate($this->option('association') ? (int) $this->option('association') : null));
        $this->info($count.' evento(s) processado(s).');

        return self::SUCCESS;
    }
}
