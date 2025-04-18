<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\ConsultationService;

class Subscription extends Model
{
    protected $fillable = [
        "recharge_subscription_id",
        "recharge_customer_id",
        "shopify_product_id",
        "original_shopify_order_id",
        "product_name",
        "status",
        "questionnaire_submission_id",
        "prescription_id",
        "user_id",
        "next_charge_scheduled_at",
    ];

    protected $casts = [
        "next_charge_scheduled_at" => "date",
    ];

    protected static function booted(): void
    {
        static::created(function ($subscription) {
            // Get the user who owns the subscription.
            // Get the submission associated with the subscription.
            $user = $subscription->user;
            $submission = $subscription->questionnaireSubmission;

            if ($user && $submission) {
                // Update submission status to submitted
                $submission->update(["status" => "submitted"]);

                // Send consultation scheduling link
                $consultationService = app(ConsultationService::class);
                $consultationResult = $consultationService->sendConsultationLink(
                    $submission
                );

                if (!$consultationResult) {
                    throw new \Exception("Failed to send consultation link");
                }
            }
        });
    }

    /**
     * Get the user who owns the subscription.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the questionnaire submission associated with this subscription.
     */
    public function questionnaireSubmission()
    {
        return $this->belongsTo(QuestionnaireSubmission::class);
    }

    /**
     * Get the prescription associated with this subscription.
     */
    public function prescription()
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * Get the check-ins for this subscription.
     */
    public function checkIns()
    {
        return $this->hasMany(Checkin::class);
    }
}
