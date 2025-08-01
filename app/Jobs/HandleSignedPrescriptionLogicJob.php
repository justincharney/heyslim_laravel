<?php

namespace App\Jobs;

use App\Jobs\CreateInitialShopifyOrderJob;
use App\Jobs\SendGpLetterJob;
use App\Jobs\UpdateSubscriptionDoseJob;
use App\Models\Prescription;
use App\Notifications\PrescriptionSignedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HandleSignedPrescriptionLogicJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [60, 300, 1800]; // 1m, 5m, 30m

    /**
     * Create a new job instance.
     */
    public function __construct(public int $prescriptionId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info(
            "Starting HandleSignedPrescriptionLogicJob for prescription #{$this->prescriptionId}",
        );

        $prescription = Prescription::find($this->prescriptionId);

        if (!$prescription) {
            Log::error(
                "Prescription not found in HandleSignedPrescriptionLogicJob",
                ["prescription_id" => $this->prescriptionId],
            );
            $this->fail("Prescription not found: " . $this->prescriptionId);
            return;
        }

        // Refresh the prescription to ensure we have the latest data
        $prescription->refresh();

        // The controller already updated the status to 'active'
        // We can double-check to be sure.
        if ($prescription->status !== "active") {
            Log::warning(
                "HandleSignedPrescriptionLogicJob running for a prescription that is not active.",
                [
                    "prescription_id" => $this->prescriptionId,
                    "status" => $prescription->status,
                ],
            );
            // We can decide to continue or stop. For now, let's continue.
        }

        // Link prescription to subscription when signed (ONLY for initial prescriptions)
        $isReplacement = !is_null($prescription->replaces_prescription_id);
        $subscription = null;

        if ($isReplacement) {
            // For replacement prescriptions, get subscription directly (already linked)
            $subscription = $prescription->subscription;
            Log::info(
                "Skipping subscription linking for replacement prescription",
                [
                    "prescription_id" => $prescription->id,
                    "replaces_prescription_id" =>
                        $prescription->replaces_prescription_id,
                ],
            );
        } else {
            // For initial prescriptions, link to subscription
            try {
                $subscription =
                    $prescription->clinicalPlan?->questionnaireSubmission
                        ?->subscription;
                if ($subscription && !$subscription->prescription_id) {
                    $subscription->update([
                        "prescription_id" => $prescription->id,
                    ]);
                    Log::info("Linked prescription to subscription", [
                        "prescription_id" => $prescription->id,
                        "subscription_id" => $subscription->id,
                    ]);
                } elseif ($subscription && $subscription->prescription_id) {
                    Log::warning(
                        "Subscription already has a prescription linked. Cannot link again.",
                        [
                            "prescription_id" => $prescription->id,
                            "subscription_id" => $subscription->id,
                        ],
                    );
                } else {
                    Log::error(
                        "Could not find subscription to link for prescription",
                        ["prescription_id" => $prescription->id],
                    );
                }
            } catch (\Exception $e) {
                Log::error("Failed to link prescription to subscription", [
                    "prescription_id" => $prescription->id,
                    "error" => $e->getMessage(),
                ]);
                $this->fail($e); // Fails the job, it will be retried
                return;
            }
        }

        // Update Chargebee subscription plan for replacement prescriptions
        if ($isReplacement && $subscription) {
            // For replacement prescriptions, always start with the first dose (index 0)
            UpdateSubscriptionDoseJob::dispatch($prescription->id, 0);
            Log::info(
                "Dispatched UpdateSubscriptionDoseJob for replacement prescription",
                [
                    "prescription_id" => $prescription->id,
                    "subscription_id" => $subscription->id,
                    "dose_index" => 0,
                ],
            );
        }

        // Dispatch the job to generate and send the GP letter
        SendGpLetterJob::dispatch($prescription->id);

        // Notify the patient that their treatment is approved
        $prescription->load("patient");
        if ($prescription->patient) {
            $prescription->patient->notify(
                new PrescriptionSignedNotification($prescription),
            );
            Log::info(
                "Dispatched PrescriptionSignedNotification for patient.",
                [
                    "prescription_id" => $prescription->id,
                    "patient_id" => $prescription->patient->id,
                ],
            );
        } else {
            Log::error(
                "Could not find patient to notify for prescription #{$this->prescriptionId}",
            );
        }

        // Conditionally dispatch job based on prescription type
        if ($isReplacement) {
            // This is a replacement prescription - do NOT dispatch job here
            Log::info(
                "Prescription #{$prescription->id} is a replacement. Signed document uploaded but no Shopify order job dispatched. ChargebeeWebhookController will handle attachment to future orders.",
            );
        } else {
            // This is an initial prescription that needs a Shopify order created
            CreateInitialShopifyOrderJob::dispatch($prescription->id);
            Log::info(
                "Dispatched CreateInitialShopifyOrderJob for initial prescription #{$prescription->id}",
            );
        }

        Log::info(
            "HandleSignedPrescriptionLogicJob finished for prescription #{$this->prescriptionId}",
        );
    }
}
