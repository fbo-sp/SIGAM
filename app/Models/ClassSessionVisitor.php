<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSessionVisitor extends Model
{
    protected $fillable = [
        'association_id', 'host_dojo_id', 'class_session_id', 'student_id', 'status',
        'authorized_by_user_id', 'authorized_at', 'revoked_by_user_id', 'revoked_at', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return ['authorized_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function hostDojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class, 'host_dojo_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
