<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable as AuditableTrait;

class SoapChart extends Model implements AuditableContract
{
    use HasFactory, AuditableTrait;

    protected $with = ["audits.user"];

    protected $fillable = [
        "patient_id",
        "provider_id",
        "title",
        "subjective",
        "objective",
        "assessment",
        "plan",
        "status",
    ];

    /**
     * Get the patient associated with the SOAP chart.
     */
    public function patient()
    {
        return $this->belongsTo(User::class, "patient_id");
    }

    /**
     * Get the provider who created the SOAP chart.
     */
    public function provider()
    {
        return $this->belongsTo(User::class, "provider_id");
    }

    /**
     * Check if the chart is a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === "draft";
    }

    /**
     * Check if the chart is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === "completed";
    }

    /**
     * Check if the chart is reviewed.
     */
    public function isReviewed(): bool
    {
        return $this->status === "reviewed";
    }

    /**
     * Scope to get charts for a specific patient.
     */
    public function scopeForPatient($query, $patientId)
    {
        return $query->where("patient_id", $patientId);
    }

    /**
     * Scope to get charts by a specific provider.
     */
    public function scopeByProvider($query, $providerId)
    {
        return $query->where("provider_id", $providerId);
    }

    /**
     * Scope to get charts by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where("status", $status);
    }
}
