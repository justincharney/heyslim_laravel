<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable as AuditableTrait;

class CheckIn extends Model implements AuditableContract
{
    use AuditableTrait;

    protected $fillable = [
        "subscription_id",
        "prescription_id",
        "user_id",
        "due_date",
        "completed_at",
        "status",
        "questions_and_responses",
        "notification_sent",
        "reviewed_by",
        "reviewed_at",
        "provider_notes",
    ];

    protected $casts = [
        "due_date" => "date",
        "completed_at" => "datetime",
        "questions_and_responses" => "array",
        "notification_sent" => "boolean",
        "reviewed_at" => "datetime",
    ];

    /**
     * Get the user (patient) associated with the check-in.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the prescription associated with the check-in.
     */
    public function prescription()
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * Get the subscription associated with the check-in.
     */
    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the provider who reviewed the check-in.
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, "reviewed_by");
    }

    /**
     * Check if the check-in is pending.
     */
    public function isPending(): bool
    {
        return $this->status === "pending";
    }

    /**
     * Check if the check-in is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === "completed";
    }

    /**
     * Check if the check-in is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === "cancelled";
    }

    /**
     * Check if the check-in is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date < now() && $this->status === "pending";
    }
}
