<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        "questionnaire_id",
        "question_number",
        "question_text",
        "label",
        "description",
        "question_type",
        "is_required",
        "required_answer",
        "calculated",
        "validation",
        "display_conditions",
    ];

    protected $casts = [
        "is_required" => "boolean",
        "calculated" => "json",
        "validation" => "json",
        "display_conditions" => "json",
    ];

    /*
    Get the questionnaire that owns the question.
    */
    public function questionnaire()
    {
        return $this->belongsTo(Questionnaire::class);
    }

    /*
    Get options for the question
    */
    public function options()
    {
        return $this->hasMany(QuestionOption::class);
    }
}
