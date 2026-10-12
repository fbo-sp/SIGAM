<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledJobRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['job_name', 'started_at', 'finished_at', 'status', 'processed_count', 'error_count', 'message'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
