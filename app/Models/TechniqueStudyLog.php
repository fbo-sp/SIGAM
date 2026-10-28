<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Estudei esta técnica" no portal: um registro por aluno, item e dia. */
class TechniqueStudyLog extends Model
{
    protected $fillable = ['association_id', 'student_id', 'technique_item_id', 'user_id', 'studied_on'];

    protected function casts(): array
    {
        return ['studied_on' => 'date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TechniqueItem::class, 'technique_item_id');
    }
}
