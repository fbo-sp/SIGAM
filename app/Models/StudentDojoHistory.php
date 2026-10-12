<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDojoHistory extends Model
{
    protected $table = 'student_dojo_history';

    protected $fillable = ['student_id', 'dojo_id', 'start_date', 'end_date', 'is_primary', 'reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'is_primary' => 'boolean'];
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }
}
