<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Discount extends Model
{
    protected $fillable = ['student_id', 'student_plan_id', 'discount_type', 'discount_value', 'reason', 'start_date', 'end_date', 'requested_by', 'requested_at', 'approved_by', 'approved_at', 'decision_notes', 'status'];

    protected function casts(): array
    {
        return ['discount_value' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date', 'requested_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function studentPlan(): BelongsTo
    {
        return $this->belongsTo(StudentPlan::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
