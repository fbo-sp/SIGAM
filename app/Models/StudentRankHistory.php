<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRankHistory extends Model
{
    protected $table = 'student_rank_history';

    protected $fillable = ['student_martial_art_id', 'rank_id', 'achieved_at', 'awarded_by_person_id', 'source_type', 'source_id', 'notes'];

    protected function casts(): array
    {
        return ['achieved_at' => 'date'];
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function studentMartialArt(): BelongsTo
    {
        return $this->belongsTo(StudentMartialArt::class);
    }
}
