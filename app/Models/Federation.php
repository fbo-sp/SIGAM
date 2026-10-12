<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Federation extends Model
{
    protected $fillable = ['association_id', 'name', 'acronym', 'federation_type', 'state', 'country', 'email', 'phone', 'website', 'status'];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FederationMembership::class);
    }
}
