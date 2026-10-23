<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizQuestionOption extends Model
{
    protected $fillable = ['quiz_question_id', 'text', 'is_correct', 'sort_order'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }
}
