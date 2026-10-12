<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dojo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'association_id',
        'code',
        'name',
        'email',
        'phone',
        'postal_code',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'country',
        'capacity',
        'started_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'date'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
