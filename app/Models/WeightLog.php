<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeightLog extends Model
{
    protected $fillable = ["user_id", "weight", "unit", "log_date"];

    protected $casts = [
        "weight" => "decimal:2",
        "log_date" => "date",
    ];

    // Get the user that logged this weight
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Convert weight to kg if it's in lbs
    public function getWeightInKgAttribute(): float
    {
        if ($this->unit === "lbs") {
            return round($this->weight * 0.453592, 2);
        }
        return $this->weight;
    }

    // Convert weight to lbs if it's in kg
    public function getWeightInLbsAttribute(): float
    {
        if ($this->unit === "kg") {
            return round($this->weight * 2.20462, 2);
        }
        return $this->weight;
    }
}
