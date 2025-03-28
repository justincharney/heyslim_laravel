<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionAnswer extends Model
{
    use HasFactory;
    protected $table = "question_answers";

    protected $fillable = ["submission_id", "question_id", "answer_text"];

    /*
    Get the submission that owns the answer.
    */
    public function submission()
    {
        return $this->belongsTo(
            QuestionnaireSubmission::class,
            "submission_id"
        );
    }

    /*
    Get the question for this answer.
    */
    public function question()
    {
        return $this->belongsTo(Question::class, "question_id");
    }
}
