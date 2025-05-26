<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable as AuditableTrait;

class QuestionnaireSubmission extends Model implements AuditableContract
{
    use HasFactory, AuditableTrait;
    protected $table = "questionnaire_submissions";

    protected $with = ["audits.user"];

    protected $fillable = [
        "questionnaire_id",
        "user_id",
        "status",
        "review_notes",
        "reviewed_by",
        "reviewed_at",
    ];

    protected $casts = [
        "submitted_at" => "datetime",
        "reviewed_at" => "datetime",
    ];

    /*
    Get the questionnaire that owns the submission.
    */
    public function questionnaire()
    {
        return $this->belongsTo(Questionnaire::class);
    }

    /*
    Get the user that owns the submission.
    */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*
    Get the reviewer user.
    */
    public function reviewer()
    {
        return $this->belongsTo(User::class, "reviewed_by");
    }

    /*
    Get the answers for the submission.
    */
    public function answers()
    {
        return $this->hasMany(QuestionAnswer::class, "submission_id");
    }

    /**
     * Get the subscription associated with the submission.
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function clinicalPlan()
    {
        return $this->hasOne(ClinicalPlan::class);
    }
}
