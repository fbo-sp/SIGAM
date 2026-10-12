<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['association_id', 'event_type', 'channel', 'subject_template', 'body_template', 'status'];
}
