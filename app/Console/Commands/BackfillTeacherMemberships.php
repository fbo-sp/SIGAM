<?php

namespace App\Console\Commands;

use App\Models\TeacherAssociationMembership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTeacherMemberships extends Command
{
    protected $signature = 'sigam:access:backfill-teachers {--apply : Persiste apenas vínculos institucionais comprovados}';

    protected $description = 'Inventaria professores existentes e, com --apply, cria vínculos institucionais sem conceder login ou acesso a dojo.';

    public function handle(): int
    {
        $eligible = DB::table('teacher_profiles as tp')
            ->join('association_person_roles as apr', 'apr.person_id', '=', 'tp.person_id')
            ->join('associations as a', 'a.id', '=', 'apr.association_id')
            ->where('tp.status', 'active')->where('apr.status', 'active')->where('a.status', 'active')
            ->whereDate('apr.start_date', '<=', today())
            ->where(fn ($q) => $q->whereNull('apr.end_date')->orWhereDate('apr.end_date', '>=', today()))
            ->select(['apr.association_id', 'tp.id as teacher_profile_id'])->distinct();

        $total = (clone $eligible)->count();
        $missing = DB::query()->fromSub($eligible, 'eligible')->leftJoin('teacher_association_memberships as tam', function ($join) {
            $join->on('tam.association_id', '=', 'eligible.association_id')->on('tam.teacher_profile_id', '=', 'eligible.teacher_profile_id');
        })->whereNull('tam.id')->count();

        $this->table(['Indicador', 'Quantidade'], [['Vínculos comprováveis', $total], ['Vínculos ainda ausentes', $missing]]);
        if (! $this->option('apply')) {
            $this->warn('Simulação concluída. Nenhum dado foi alterado. Use --apply após revisar as contagens.');

            return self::SUCCESS;
        }

        $created = 0;
        (clone $eligible)->orderBy('apr.association_id')->orderBy('tp.id')->chunk(200, function ($rows) use (&$created): void {
            foreach ($rows as $row) {
                $membership = TeacherAssociationMembership::query()->firstOrCreate(
                    ['association_id' => $row->association_id, 'teacher_profile_id' => $row->teacher_profile_id],
                    ['status' => 'active', 'source' => 'legacy_backfill'],
                );
                $created += $membership->wasRecentlyCreated ? 1 : 0;
            }
        });
        $this->info("{$created} vínculo(s) institucional(is) criado(s). Nenhuma conta, senha, função ou concessão por dojo foi criada.");

        return self::SUCCESS;
    }
}
