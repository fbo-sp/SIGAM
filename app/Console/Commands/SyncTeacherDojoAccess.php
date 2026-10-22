<?php

namespace App\Console\Commands;

use App\Models\DojoTeacherAssignment;
use App\Services\Access\TeacherDojoAccess;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncTeacherDojoAccess extends Command
{
    protected $signature = 'sigam:access:sync-teacher-dojos {--apply : Cria as concessões de professor que faltam}';

    protected $description = 'Libera o acesso de Professor do dojo para vínculos vigentes ou futuros de professores com conta. Concessões existentes não são alteradas.';

    public function handle(TeacherDojoAccess $access): int
    {
        $links = DojoTeacherAssignment::query()->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->orderBy('id')->get();
        $this->info("{$links->count()} vínculo(s) vigente(s) ou futuro(s) de professor com dojo.");
        if (! $this->option('apply')) {
            $this->warn('Simulação concluída. Nenhum dado foi alterado. Use --apply para criar as concessões que faltam.');

            return self::SUCCESS;
        }
        $created = $links->filter(fn (DojoTeacherAssignment $link) => $access->grantForLink($link, $link->created_by_user_id) !== null)->count();
        $this->info("{$created} concessão(ões) de Professor do dojo criada(s).");

        return self::SUCCESS;
    }
}
