<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\ConsultationService;
use Illuminate\Support\Facades\Log;

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
            // Only process relationships if the required foreign key is set
            if ($subscription->questionnaire_submission_id) {
                try {
                    // Get the submission associated with the subscription
                    $subscription->load("questionnaireSubmission");
                    $submission = $subscription->questionnaireSubmission;

                    if ($submission) {
                        // Update submission status if not already submitted
                        if ($submission->status !== "submitted") {
                            $submission->update(["status" => "submitted"]);

                            // Send consultation scheduling link
                            $consultationService = app(
                                ConsultationService::class
                            );
                            $consultationResult = $consultationService->sendConsultationLink(
                                $submission
                            );

                            if (!$consultationResult) {
                                Log::error("Failed to send consultation link", [
                                    "submission_id" => $submission->id,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error(
                        "Error in subscription created event: " .
                            $e->getMessage(),
                        [
                            "subscription_id" => $subscription->id,
                            "trace" => $e->getTraceAsString(),
                        ]
                    );
                }
            }
        });

        static::updated(function ($subscription) {
            // Only run if these fields were previously null but are now set
            if (
                $subscription->user_id &&
                $subscription->questionnaire_submission_id &&
                $subscription->isDirty("questionnaire_submission_id")
            ) {
                try {
                    $subscription->load("questionnaireSubmission");
                    $submission = $subscription->questionnaireSubmission;

                    if ($submission) {
                        // Update submission status if not already submitted
                        if ($submission->status !== "submitted") {
                            $submission->update(["status" => "submitted"]);

                            // Send consultation scheduling link
                            $consultationService = app(
                                ConsultationService::class
                            );
                            $consultationResult = $consultationService->sendConsultationLink(
                                $submission
                            );

                            if (!$consultationResult) {
                                Log::error("Failed to send consultation link", [
                                    "submission_id" => $submission->id,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error(
                        "Error in subscription updated event: " .
                            $e->getMessage()
                    );
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
