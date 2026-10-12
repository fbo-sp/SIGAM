<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectRegistrationRequest extends Model
{
    protected $fillable = [
        'association_id', 'dojo_id', 'created_by_user_id', 'operation', 'idempotency_key',
        'result_type', 'result_id', 'document_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
