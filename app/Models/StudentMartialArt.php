<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentMartialArt extends Model
{
    protected $table = 'student_martial_arts';

    protected $fillable = ['student_id', 'martial_art_id', 'start_date', 'end_date', 'status', 'current_rank_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function currentRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'current_rank_id');
    }

    public function rankHistory(): HasMany
    {
        return $this->hasMany(StudentRankHistory::class);
    }
}
