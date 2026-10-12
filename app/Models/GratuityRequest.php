<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GratuityRequest extends Model
{
    protected $fillable = ['association_id', 'dojo_id', 'student_id', 'reason', 'justification', 'start_date', 'end_date', 'requested_by', 'requested_at', 'dojo_approver_id', 'dojo_decision', 'dojo_decision_at', 'dojo_notes', 'association_approver_id', 'association_decision', 'association_decision_at', 'association_notes', 'status'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'requested_at' => 'datetime', 'dojo_decision_at' => 'datetime', 'association_decision_at' => 'datetime'];
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
