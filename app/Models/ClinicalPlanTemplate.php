<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalPlanTemplate extends Model
{
    protected $fillable = [
        "name",
        "description",
        "condition_treated",
        "medicines_that_may_be_prescribed",
        "dose_schedule",
        "guidelines",
        "monitoring_frequency",
        "process_for_reporting_adrs",
        "created_by",
        "is_global",
        "team_id",
    ];

    /**
     * Get the creator of the clinical plan template.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, "created_by");
    }

    /**
     * Get the team associated with the clinical plan template.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
