<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherMartialArt extends Model
{
    protected $table = 'teacher_martial_arts';

    protected $fillable = ['teacher_profile_id', 'martial_art_id', 'rank_id', 'federation_registration', 'certification_expiration', 'status'];

    protected function casts(): array
    {
        return ['certification_expiration' => 'date'];
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }
}
