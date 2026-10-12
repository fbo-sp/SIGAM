<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherInvitation extends Model
{
    protected $fillable = [
        'association_id', 'teacher_profile_id', 'user_id', 'email', 'token_hash', 'encrypted_token',
        'status', 'delivery_status', 'attempt_count', 'scheduled_at', 'last_attempt_at', 'sent_at',
        'email_verified_at', 'accepted_at', 'declined_at', 'revoked_at', 'expires_at', 'error_message',
        'invited_by_user_id',
    ];

    protected $hidden = ['token_hash', 'encrypted_token'];

    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'scheduled_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function displayState(): string
    {
        if (in_array($this->status, ['pending', 'sent'], true) && $this->expires_at->isPast()) {
            return 'expired';
        }

        return $this->status;
    }
}
