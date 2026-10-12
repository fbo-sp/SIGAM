<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialSetting extends Model
{
    protected $attributes = [
        'nonexistent_day_policy' => 'last_day',
        'weekend_policy' => 'none',
        'discounts_require_approval' => true,
    ];

    protected $fillable = ['association_id', 'nonexistent_day_policy', 'weekend_policy', 'holiday_dates', 'discounts_require_approval'];

    protected function casts(): array
    {
        return ['holiday_dates' => 'array', 'discounts_require_approval' => 'boolean'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
