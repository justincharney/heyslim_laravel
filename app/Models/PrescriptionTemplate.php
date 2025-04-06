<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionTemplate extends Model
{
    protected $fillable = [
        "name",
        "description",
        "medication_name",
        "dose",
        "schedule",
        "refills",
        "directions",
        "created_by",
        "is_global",
        "team_id",
    ];

    /**
     * Get the creator of the prescription template.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, "created_by");
    }

    /**
     * Get the team associated with the prescription template.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
