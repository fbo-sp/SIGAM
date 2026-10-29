<?php

namespace App\Console\Commands;

use App\Services\Operations\ProductionReadiness;
use App\Services\People\PersonPhotoService;
use App\Support\Export\XlsxReport;
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
            // Informativo: sem GD o sistema funciona, só não aceita fotos. Não muda o resultado do comando.
            $this->line(PersonPhotoService::supported() ? 'Fotos de pessoas: extensão GD disponível.' : 'Fotos de pessoas: extensão GD ausente; o envio de fotos ficará indisponível.');
            $this->line(XlsxReport::supported() ? 'Relatórios em Excel: extensões zip e xmlwriter disponíveis.' : 'Relatórios em Excel: falta a extensão zip ou xmlwriter; os downloads em Excel ficarão indisponíveis.');
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
