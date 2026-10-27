<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    protected $fillable = ['association_id', 'dojo_id', 'sender_user_id', 'sender_level', 'target_type', 'target', 'title', 'body', 'send_email', 'recipient_count', 'idempotency_key', 'sent_at'];

    protected function casts(): array
    {
        return ['target' => 'array', 'send_email' => 'boolean', 'sent_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function dojo(): BelongsTo
    {
        return $this->belongsTo(Dojo::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }
}
