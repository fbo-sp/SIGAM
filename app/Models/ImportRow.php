<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $fillable = [
        'import_batch_id', 'row_number', 'row_hash', 'raw_payload', 'normalized_payload', 'status', 'initial_status',
        'message', 'result_type', 'result_id', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'normalized_payload' => 'array', 'processed_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
