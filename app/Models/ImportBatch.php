<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'association_id', 'dojo_id', 'created_by_user_id', 'import_type', 'original_name', 'stored_name',
        'storage_path', 'mime_type', 'file_size', 'file_hash', 'template_version', 'status', 'total_rows',
        'ready_rows', 'review_rows', 'invalid_rows', 'processed_rows', 'failed_rows', 'confirmed_at',
        'completed_at', 'file_deleted_at', 'error_summary',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'completed_at' => 'datetime', 'file_deleted_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
