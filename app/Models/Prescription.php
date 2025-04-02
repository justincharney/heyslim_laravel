<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Prescription extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        "start_date" => "date",
        "end_date" => "date",
    ];

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
}
