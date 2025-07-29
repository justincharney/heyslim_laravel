<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Services\ChargebeeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateSubscriptionDoseJob implements ShouldQueue
{
    use Queueable;

    protected int $prescriptionId;
    protected int $doseIndex;

    /**
     * Create a new job instance.
     */
    public function __construct(int $prescriptionId, int $doseIndex)
    {
        $this->prescriptionId = $prescriptionId;
        $this->doseIndex = $doseIndex;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Find the prescription with its relationships
            $prescription = Prescription::with([
                "clinicalPlan.questionnaireSubmission.subscription",
                "subscription",
            ])->find($this->prescriptionId);

            if (!$prescription) {
                Log::error(
                    "Prescription not found for UpdateSubscriptionDoseJob",
                    [
                        "prescription_id" => $this->prescriptionId,
                    ],
                );
                return;
            }

            // Get the subscription (try direct relationship first, then via questionnaire)
            $subscription =
                $prescription->subscription ??
                $prescription->clinicalPlan?->questionnaireSubmission
                    ?->subscription;

            if (!$subscription) {
                Log::error(
                    "No subscription found for prescription dose update",
                    [
                        "prescription_id" => $this->prescriptionId,
                    ],
                );
                return;
            }

            // Get the dose schedule
            $doseSchedule = $prescription->dose_schedule;
            if (!$doseSchedule) {
                Log::error("No dose schedule found for prescription", [
                    "prescription_id" => $this->prescriptionId,
                ]);
                return;
            }

            if (!isset($doseSchedule[$this->doseIndex])) {
                Log::error("Dose index not found in schedule", [
                    "prescription_id" => $this->prescriptionId,
                    "dose_index" => $this->doseIndex,
                    "available_doses" => count($doseSchedule),
                ]);
                return;
            }

            $newDose = $doseSchedule[$this->doseIndex];
            $newItemPriceId = $newDose["chargebee_item_price_id"] ?? null;

            if (!$newItemPriceId) {
                Log::error("Could not find Chargebee item price for dose", [
                    "prescription_id" => $this->prescriptionId,
                    "dose" => $newDose["dose"],
                ]);
                return;
            }

            // Update the Chargebee subscription
            $chargebeeService = app(ChargebeeService::class);
            $success = $chargebeeService->updateSubscriptionPlan(
                $subscription->chargebee_subscription_id,
                $newItemPriceId,
            );

            if ($success) {
                // Verify the update by fetching the subscription from Chargebee
                $updatedSubscription = $chargebeeService->getSubscription(
                    $subscription->chargebee_subscription_id,
                );

                $actuallyUpdated = false;
                if (
                    $updatedSubscription &&
                    isset(
                        $updatedSubscription["subscription"][
                            "subscription_items"
                        ],
                    )
                ) {
                    foreach (
                        $updatedSubscription["subscription"][
                            "subscription_items"
                        ]
                        as $item
                    ) {
                        if ($item["item_price_id"] === $newItemPriceId) {
                            $actuallyUpdated = true;
                            break;
                        }
                    }
                }

                if ($actuallyUpdated) {
                    // Update local subscription record only if Chargebee was actually updated
                    $subscription->update([
                        "chargebee_item_price_id" => $newItemPriceId,
                    ]);

                    // Log::info("Successfully updated subscription dose", [
                    //     "prescription_id" => $this->prescriptionId,
                    //     "subscription_id" => $subscription->id,
                    //     "chargebee_subscription_id" =>
                    //         $subscription->chargebee_subscription_id,
                    //     "new_item_price_id" => $newItemPriceId,
                    //     "dose_index" => $this->doseIndex,
                    //     "new_dose_strength" => $newDose["dose"],
                    // ]);
                } else {
                    Log::error(
                        "Chargebee update appeared successful but verification failed",
                        [
                            "prescription_id" => $this->prescriptionId,
                            "chargebee_subscription_id" =>
                                $subscription->chargebee_subscription_id,
                            "expected_item_price_id" => $newItemPriceId,
                            "actual_subscription_data" => $updatedSubscription,
                        ],
                    );
                }
            } else {
                Log::error("Failed to update Chargebee subscription dose", [
                    "prescription_id" => $this->prescriptionId,
                    "subscription_id" =>
                        $subscription->chargebee_subscription_id,
                    "new_item_price_id" => $newItemPriceId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Exception in UpdateSubscriptionDoseJob", [
                "prescription_id" => $this->prescriptionId,
                "dose_index" => $this->doseIndex,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
        }
    }
}
