<?php

namespace App\Jobs;

use App\Models\QuestionnaireSubmission;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\QuestionnaireRejectedNotification;
use App\Services\ChargebeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRejectedQuestionnaireJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 300, 600];

    protected $submissionId;
    protected $providerId;
    protected $reviewNotes;

    /**
     * Create a new job instance.
     *
     * @param int $submissionId
     * @param int $providerId
     * @param string $reviewNotes
     */
    public function __construct(
        int $submissionId,
        int $providerId,
        string $reviewNotes,
    ) {
        $this->submissionId = $submissionId;
        $this->providerId = $providerId;
        $this->reviewNotes = $reviewNotes;
    }

    /**
     * Execute the job.
     *
     * @param \App\Services\ChargebeeService $chargebeeService
     * @return void
     */
    public function handle(ChargebeeService $chargebeeService): void
    {
        Log::info(
            "Processing questionnaire rejection job for submission ID: {$this->submissionId}",
        );

        $submission = QuestionnaireSubmission::with([
            "user",
            "subscription.prescription",
        ])->find($this->submissionId);

        if (!$submission) {
            Log::error(
                "Submission {$this->submissionId} not found in ProcessRejectedQuestionnaireJob.",
            );
            $this->fail("Submission not found.");
            return;
        }

        $patient = $submission->user;
        if (!$patient) {
            Log::error(
                "Patient not found for submission {$this->submissionId} in ProcessRejectedQuestionnaireJob.",
            );
            $this->fail("Patient not found for submission.");
            return;
        }

        $provider = User::find($this->providerId);
        if (!$provider) {
            Log::warning(
                "Provider {$this->providerId} not found in ProcessRejectedQuestionnaireJob. Notification will not include provider name.",
            );
        }

        // --- Task 1: Cancel Subscription if it exists ---
        $subscription = $submission->subscription;

        if ($subscription) {
            try {
                Log::info(
                    "Attempting to cancel Chargebee subscription for rejected questionnaire.",
                    [
                        "submission_id" => $this->submissionId,
                        "subscription_id" => $subscription->id,
                        "chargebee_subscription_id" =>
                            $subscription->chargebee_subscription_id,
                    ],
                );

                $cancellationSuccess = $chargebeeService->cancelSubscription(
                    $subscription->chargebee_subscription_id,
                    "customer_request", // Or a more specific reason code
                    false, // Cancel immediately
                );

                if ($cancellationSuccess) {
                    DB::transaction(function () use ($subscription) {
                        $subscription->status = "cancelled";
                        $subscription->save();

                        if ($subscription->prescription) {
                            $prescription = $subscription->prescription;
                            $prescription->load("clinicalPlan");
                            $prescription->status = "cancelled";
                            $prescription->save();

                            // Also mark the associated clinical plan as completed
                            if ($prescription->clinicalPlan) {
                                $prescription->clinicalPlan->status =
                                    "completed";
                                $prescription->clinicalPlan->save();
                            }
                        }
                    });

                    Log::info(
                        "Successfully cancelled Chargebee subscription and updated local records for submission #{$this->submissionId}.",
                    );
                } else {
                    Log::error(
                        "Chargebee cancellation failed for submission #{$this->submissionId}. Releasing job for retry.",
                        [
                            "chargebee_subscription_id" =>
                                $subscription->chargebee_subscription_id,
                        ],
                    );
                    $this->release(60 * 2); // Retry cancellation in 2 minutes
                    return; // Stop processing this attempt
                }
            } catch (\Exception $e) {
                Log::error(
                    "Exception during subscription cancellation in ProcessRejectedQuestionnaireJob",
                    [
                        "submission_id" => $this->submissionId,
                        "error" => $e->getMessage(),
                    ],
                );
                $this->release(60 * 5); // Retry on general exception
                return;
            }
        } else {
            Log::info(
                "No active Chargebee subscription found to cancel for submission #{$this->submissionId}.",
            );
        }

        // --- Task 2: Send Notification ---
        try {
            $patient->notify(
                new QuestionnaireRejectedNotification(
                    $submission,
                    $provider,
                    $this->reviewNotes,
                ),
            );
            Log::info(
                "Sent QuestionnaireRejectedNotification for submission #{$this->submissionId}",
            );
        } catch (\Exception $e) {
            Log::error(
                "Failed to send QuestionnaireRejectedNotification for submission #{$this->submissionId}",
                [
                    "error" => $e->getMessage(),
                ],
            );
            // Don't fail the job just because notification failed.
        }

        Log::info(
            "ProcessRejectedQuestionnaireJob completed for submission #{$this->submissionId}",
        );
    }
}
