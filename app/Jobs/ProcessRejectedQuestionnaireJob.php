<?php

namespace App\Jobs;

use App\Models\QuestionnaireSubmission;
use App\Models\User;
use App\Notifications\QuestionnaireRejectedNotification;
use App\Services\RechargeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRejectedQuestionnaireJob implements ShouldQueue
{
    use Queueable, Dispatchable, SerializesModels, InteractsWithQueue;

    public $tries = 5;
    public $backoff = [30, 300, 600];

    protected $submissionId;
    protected $providerId;
    protected $reviewNotes;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $submissionId,
        int $providerId,
        string $reviewNotes
    ) {
        $this->submissionId = $submissionId;
        $this->providerId = $providerId;
        $this->reviewNotes = $reviewNotes;
    }

    /**
     * Execute the job.
     */
    public function handle(RechargeService $rechargeService): void
    {
        Log::info(
            "Processing questionnaire rejection job for submission ID: {$this->submissionId}"
        );

        // Get the questionnaire submission to reject
        $submission = QuestionnaireSubmission::with("user")->find(
            $this->submissionId
        );
        if (!$submission) {
            Log::error(
                "Submission {$this->submissionId} not found in ProcessRejectedQuestionnaireJob."
            );
            $this->fail("Submission not found.");
            return;
        }

        $patient = $submission->user;
        if (!$patient) {
            Log::error(
                "Patient not found for submission {$this->submissionId} in ProcessRejectedQuestionnaireJob."
            );
            $this->fail("Patient not found for submission.");
            return;
        }

        $provider = User::find($this->providerId);
        if (!$provider) {
            Log::warning(
                "Provider {$this->providerId} not found in ProcessRejectedQuestionnaireJob. Notification cannot include provider name."
            );
        }

        // --- Task 1: Cancel Subscription & Refund Order ---
        try {
            $cancellationSuccess = $rechargeService->cancelSubscriptionForRejectedQuestionnaire(
                $submission,
                "Questionnaire rejected by healthcare provider" // Reason for Recharge/Shopify
            );

            if (!$cancellationSuccess) {
                Log::error(
                    "cancelSubscriptionForRejectedQuestionnaire failed for submission #{$this->submissionId}. Releasing job for retry."
                );
                $this->release(60 * 2); // Retry cancellation in 2 minutes
                return; // Stop processing this attempt
            }

            Log::info(
                "Successfully processed cancellations (Shopify/Recharge) for rejected submission #{$this->submissionId}."
            );
        } catch (\Exception $e) {
            Log::error(
                "Exception during subscription cancellation in ProcessRejectedQuestionnaireJob",
                [
                    "submission_id" => $this->submissionId,
                    "error" => $e->getMessage(),
                ]
            );
            $this->release(60 * 5); // Retry on general exception
            return;
        }

        // --- Task 2: Send Notification ---
        // We attempt this even if cancellation had issues, as the user needs to know about the rejection.
        try {
            $patient->notify(
                new QuestionnaireRejectedNotification(
                    $submission,
                    $provider,
                    $this->reviewNotes
                )
            );
            // $submission->update(['rejection_notified_at' => now()]); // Update flag
            Log::info(
                "Sent QuestionnaireRejectedNotification for submission #{$this->submissionId}"
            );
            // } else {
            //     Log::info("Rejection notification already sent for submission #{$this->submissionId}.");
            // }
        } catch (\Exception $e) {
            Log::error(
                "Failed to send QuestionnaireRejectedNotification for submission #{$this->submissionId}",
                [
                    "error" => $e->getMessage(),
                ]
            );
        }

        Log::info(
            "ProcessRejectedQuestionnaireJob completed for submission #{$this->submissionId}"
        );
    }
}
