<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['association_id', 'charge_id', 'idempotency_key', 'request_hash', 'payment_date', 'posted_at', 'amount', 'payment_method', 'reference', 'receipt_path', 'notes', 'status', 'created_by', 'reversed_by', 'reversed_at', 'reversal_reason'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
