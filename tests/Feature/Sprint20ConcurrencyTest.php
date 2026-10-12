<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\MartialArt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Sprint20ConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_two_mysql_processes_do_not_exceed_the_group_target(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Concorrência real é validada somente no MySQL.');
        }

        if (! Schema::hasTable('class_groups')) {
            $this->markTestSkipped('O schema MySQL de teste precisa estar migrado antes deste teste.');
        }
        Carbon::setTestNow('2026-10-11 12:00:00');
        $association = Association::query()->create(['legal_name' => 'Associação concorrente', 'trade_name' => 'Associação concorrente']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'CONC', 'name' => 'Dojo concorrente']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô concorrente']);
        $group = ClassGroup::query()->create([
            'association_id' => $association->id,
            'dojo_id' => $dojo->id,
            'martial_art_id' => $art->id,
            'name' => 'Turma concorrente',
            'future_session_target' => 8,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $group->schedules()->create([
            'weekday' => 1,
            'start_time' => '18:00',
            'end_time' => '19:00',
            'valid_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $command = [PHP_BINARY, base_path('artisan'), 'sigam:sessions:generate', '--association='.$association->id];
        $first = new Process($command, base_path(), null, null, 30);
        $second = new Process($command, base_path(), null, null, 30);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $this->assertSame(8, $group->sessions()->where('status', 'planned')->count());
        $this->assertSame(8, $group->sessions()->count());

        DB::table('audit_logs')->where('association_id', $association->id)->delete();
        DB::table('class_sessions')->where('class_group_id', $group->id)->delete();
        DB::table('class_schedules')->where('class_group_id', $group->id)->delete();
        $group->forceDelete();
        $art->delete();
        $dojo->forceDelete();
        $association->forceDelete();
    }
}
