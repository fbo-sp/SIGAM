<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationCounter extends Model
{
    protected $fillable = ['association_id', 'entity_type', 'next_number'];
}
