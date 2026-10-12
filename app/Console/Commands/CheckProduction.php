<?php

namespace App\Console\Commands;

use App\Services\Operations\ProductionReadiness;
use Illuminate\Console\Command;

class CheckProduction extends Command
{
    protected $signature = 'sigam:production:check {--json}';

    protected $description = 'Verifica requisitos técnicos de produção sem imprimir segredos';

    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->checks();
        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT));
        } else {
            $this->table(['Verificação', 'Resultado'], collect($checks)->map(fn ($ok, $name) => [$name, $ok ? 'OK' : 'PENDENTE'])->values());
            $this->warn('Não substitui aceite de usuários, teste SMTP/cron, restauração e aprovação de privacidade.');
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
