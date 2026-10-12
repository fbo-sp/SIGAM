<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'event_type', 'internal_enabled', 'email_enabled'];

    protected function casts(): array
    {
        return ['internal_enabled' => 'boolean', 'email_enabled' => 'boolean'];
    }
}
