<?php

namespace App\Console\Commands;

use App\Models\StudentPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditOperationalOrigins extends Command
{
    protected $signature = 'sigam:operation:audit-origins {--association= : Limita o diagnóstico a uma associação} {--apply : Preenche somente origens inequívocas}';

    protected $description = 'Diagnostica origens de dojo ausentes sem exibir dados pessoais e aplica apenas inferências inequívocas.';

    public function handle(): int
    {
        $associationId = $this->option('association') ? (int) $this->option('association') : null;
        $query = StudentPlan::query()->whereNull('student_plans.dojo_id')
            ->whereHas('student', fn ($student) => $student->when($associationId, fn ($scope) => $scope->where('association_id', $associationId)))
            ->with(['student:id,association_id', 'financialPlan:id,association_id,dojo_id']);

        $missing = (clone $query)->count();
        $inferable = 0;
        $ambiguous = 0;
        $updated = 0;

        $query->orderBy('student_plans.id')->chunkById(200, function ($plans) use (&$inferable, &$ambiguous, &$updated): void {
            foreach ($plans as $plan) {
                $candidates = collect([$plan->financialPlan?->dojo_id])
                    ->merge(DB::table('charges')->where('source_type', 'monthly_fee')->where('source_id', $plan->id)->whereNotNull('dojo_id')->distinct()->pluck('dojo_id'))
                    ->filter()->map(fn ($id) => (int) $id)->unique()->values();
                $candidates = $candidates->filter(fn ($dojoId) => DB::table('dojos')->where('id', $dojoId)->where('association_id', $plan->student->association_id)->exists())->values();
                if ($candidates->count() !== 1) {
                    $ambiguous++;

                    continue;
                }
                $inferable++;
                if ($this->option('apply')) {
                    $updated += StudentPlan::query()->whereKey($plan->id)->whereNull('dojo_id')->update(['dojo_id' => $candidates->first()]);
                }
            }
        });

        $chargesMissing = DB::table('charges')->whereNull('dojo_id')->when($associationId, fn ($query) => $query->where('association_id', $associationId))->count();
        $registrationsMissing = DB::table('championship_registrations')->whereNull('dojo_id')->when($associationId, fn ($query) => $query->where('association_id', $associationId))->count();
        $this->table(['Indicador técnico', 'Quantidade'], [
            ['Vínculos de plano sem origem', $missing],
            ['Vínculos com origem inequívoca', $inferable],
            ['Vínculos que exigem revisão', $ambiguous],
            ['Cobranças históricas sem origem', $chargesMissing],
            ['Inscrições históricas sem origem', $registrationsMissing],
        ]);
        if (! $this->option('apply')) {
            $this->warn('Simulação concluída. Nenhum dado foi alterado. Revise as contagens antes de usar --apply.');
        } else {
            $this->info("{$updated} vínculo(s) de plano atualizado(s). Cobranças e inscrições históricas não foram reatribuídas.");
        }

        return self::SUCCESS;
    }
}
