<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementRecipient extends Model
{
    protected $fillable = ['announcement_id', 'person_id', 'user_id', 'notification_id', 'role', 'email', 'email_status', 'email_attempts', 'email_scheduled_at', 'email_last_attempt_at', 'email_sent_at', 'email_error'];

    protected function casts(): array
    {
        return ['email_scheduled_at' => 'datetime', 'email_last_attempt_at' => 'datetime', 'email_sent_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
