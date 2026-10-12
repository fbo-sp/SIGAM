<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeImportFiles extends Command
{
    protected $signature = 'sigam:imports:purge-files {--days=30}';

    protected $description = 'Remove arquivos privados antigos de importação, preservando lote, resultado e auditoria.';

    public function handle(AuditService $audit): int
    {
        $days = (int) $this->option('days');
        if ($days < 7 || $days > 365) {
            $this->error('Informe retenção entre 7 e 365 dias.');

            return self::FAILURE;
        }
        $count = 0;
        ImportBatch::query()->whereNull('file_deleted_at')->where('created_at', '<', now()->subDays($days))
            ->orderBy('id')->chunkById(100, function ($batches) use (&$count, $audit): void {
                foreach ($batches as $batch) {
                    $disk = Storage::disk('local');
                    if ($disk->exists($batch->storage_path) && ! $disk->delete($batch->storage_path)) {
                        continue;
                    }
                    DB::transaction(function () use ($batch): void {
                        $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                        $locked->rows()->update(['raw_payload' => json_encode([]), 'normalized_payload' => null]);
                        $locked->update([
                            'file_deleted_at' => now(),
                            'status' => $locked->status === 'completed' ? 'completed' : 'expired',
                        ]);
                    }, 3);
                    $audit->record('assisted_import.file_purged', 'updated', $batch, null, [
                        'retained_batch' => true, 'payload_scrubbed' => true,
                    ], ['association_id' => $batch->association_id]);
                    $count++;
                }
            });
        $this->info("{$count} arquivo(s) removido(s); os históricos foram preservados.");

        return self::SUCCESS;
    }
}
