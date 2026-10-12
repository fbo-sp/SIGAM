<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = ['notification_event_id', 'association_id', 'person_id', 'user_id', 'event_type', 'channel', 'recipient_email', 'subject', 'body', 'status', 'attempt_count', 'scheduled_at', 'last_attempt_at', 'sent_at', 'read_at', 'error_message'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
