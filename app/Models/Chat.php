<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = [
        "prescription_id",
        "patient_id",
        "provider_id",
        "title",
        "status",
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, "patient_id");
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, "provider_id");
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the other participant in the conversation
     */
    public function getOtherParticipant(User $user): User
    {
        if ($user->id == $this->patient_id) {
            return $this->provider;
        }
        return $this->patient;
    }
}
