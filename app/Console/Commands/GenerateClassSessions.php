<?php

namespace App\Console\Commands;

use App\Services\Attendance\FutureSessionMaintainer;
use App\Services\Operations\JobRunRecorder;
use Illuminate\Console\Command;

class GenerateClassSessions extends Command
{
    protected $signature = 'sigam:sessions:generate {--from=} {--until=} {--association=}';

    protected $description = 'Completa a meta de próximas aulas das turmas ativas, sem duplicação.';

    public function handle(FutureSessionMaintainer $maintainer, JobRunRecorder $runs): int
    {
        if ($this->option('from') || $this->option('until')) {
            $this->warn('As opções --from e --until são legadas e foram ignoradas. A geração agora respeita a meta de cada turma.');
        }

        $created = $runs->run('MaintainFutureClassSessions', fn () => $maintainer->maintainAll(
            $this->option('association') ? (int) $this->option('association') : null,
        ));
        $this->info("{$created} aula(s) criada(s).");

        return self::SUCCESS;
    }
}
