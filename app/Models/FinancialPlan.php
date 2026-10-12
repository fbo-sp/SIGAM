<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialPlan extends Model
{
    use SoftDeletes;

    protected $fillable = ['association_id', 'dojo_id', 'martial_art_id', 'name', 'description', 'billing_period', 'amount', 'valid_from', 'valid_until', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function studentPlans(): HasMany
    {
        return $this->hasMany(StudentPlan::class);
    }
}
