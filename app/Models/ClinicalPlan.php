<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable as AuditableTrait;

class ClinicalPlan extends Model implements AuditableContract
{
    use AuditableTrait;

    protected $fillable = [
        "patient_id",
        "provider_id",
        "pharmacist_id",
        "questionnaire_submission_id",
        "condition_treated",
        "medicines_that_may_be_prescribed",
        "dose_schedule",
        "guidelines",
        "monitoring_frequency",
        "process_for_reporting_adrs",
        "patient_allergies",
        "provider_agreed_at",
        "pharmacist_agreed_at",
        "status",
    ];

    protected $casts = [
        "provider_agreed_at" => "datetime",
        "pharmacist_agreed_at" => "datetime",
    ];

    /**
     * Get the patient associated with the clinical plan.
     */
    public function patient()
    {
        return $this->belongsTo(User::class, "patient_id");
    }

    /**
     * Get the provider associated with the clinical plan.
     */
    public function provider()
    {
        return $this->belongsTo(User::class, "provider_id");
    }

    /**
     * Get the pharmacist associated with the clinical plan.
     */
    public function pharmacist()
    {
        return $this->belongsTo(User::class, "pharmacist_id");
    }

    /**
     * Get the questionnaire submission associated with the clinical plan.
     */
    public function questionnaireSubmission()
    {
        return $this->belongsTo(QuestionnaireSubmission::class);
    }

    /**
     * Get the prescriptions associated with the clinical plan.
     * hasMany relationship since the prescriber could for example prescribe multiple
     * medications (e.g. GLP-1 and nausea) from a single clinical plan
     */
    public function prescriptions()
    {
        return $this->hasMany(Prescription::class);
    }
}
