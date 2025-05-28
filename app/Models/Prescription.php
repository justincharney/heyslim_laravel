<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\CheckIn;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable as AuditableTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model implements AuditableContract
{
    use HasFactory, AuditableTrait;

    protected $with = ["audits.user"];

    protected $fillable = [
        "patient_id",
        "prescriber_id",
        "clinical_plan_id",
        "medication_name",
        "dose",
        "schedule",
        "refills",
        "directions",
        "status",
        "start_date",
        "end_date",
        "yousign_signature_request_id",
        "yousign_document_id",
        "signed_at",
        "dose_schedule",
        "replaces_prescription_id",
        "replaced_by_prescription_id",
    ];

    protected $casts = [
        "start_date" => "date",
        "end_date" => "date",
        "signed_at" => "datetime",
        "dose_schedule" => "array",
    ];

    /**
     * Get the prescription that this prescription replaces.
     */
    public function replacedPrescription(): BelongsTo
    {
        return $this->belongsTo(
            Prescription::class,
            "replaces_prescription_id"
        );
    }

    /**
     * Get the prescription that replaced this one.
     */
    public function replacingPrescription(): BelongsTo
    {
        return $this->belongsTo(
            Prescription::class,
            "replaced_by_prescription_id"
        );
    }

    /**
     * Get the initial dose from the dose schedule
     */
    public function getInitialDoseAttribute()
    {
        if (
            empty($this->dose_schedule) ||
            !isset($this->dose_schedule["doses"][0])
        ) {
            return null;
        }

        return $this->dose_schedule["doses"][0];
    }

    /**
     * Get the maintenance/refill doses
     */
    public function getRefillDosesAttribute()
    {
        if (
            empty($this->dose_schedule) ||
            !isset($this->dose_schedule["doses"]) ||
            count($this->dose_schedule["doses"]) <= 1
        ) {
            return [];
        }

        // Return all doses except the first one
        return array_slice($this->dose_schedule["doses"], 1);
    }

    /**
     * Get the patient that owns the prescription.
     */
    public function patient()
    {
        return $this->belongsTo(User::class, "patient_id");
    }

    /**
     * Get the prescriber that created the prescription.
     */
    public function prescriber()
    {
        return $this->belongsTo(User::class, "prescriber_id");
    }

    /**
     * Get the clinical plan associated with the prescription.
     */
    public function clinicalPlan()
    {
        return $this->belongsTo(ClinicalPlan::class);
    }

    /**
     * Get the subscription associated with the prescription.
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Get the check-ins for this prescription.
     */
    public function checkIns()
    {
        return $this->hasMany(CheckIn::class);
    }
}
