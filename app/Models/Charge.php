<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Charge extends Model
{
    protected $fillable = ['association_id', 'dojo_id', 'student_id', 'person_id', 'source_type', 'source_id', 'description', 'competence', 'issued_at', 'due_date', 'original_amount', 'discount_amount', 'final_amount', 'paid_amount', 'balance_amount', 'discount_id', 'gratuity_request_id', 'calculation_snapshot', 'due_policy_snapshot', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['competence' => 'date', 'issued_at' => 'datetime', 'due_date' => 'date', 'original_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'final_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'balance_amount' => 'decimal:2', 'calculation_snapshot' => 'array', 'due_policy_snapshot' => 'array'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function gratuityRequest(): BelongsTo
    {
        return $this->belongsTo(GratuityRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
