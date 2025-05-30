<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Models\Subscription;
use App\Services\RechargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    protected $rechargeSubscriptionId;
    protected $prescriptionId;

    /**
     * Create a new job instance.
     *
     * @param string $rechargeSubscriptionId
     * @param int $prescriptionId
     */
    public function __construct(
        string $rechargeSubscriptionId,
        int $prescriptionId
    ) {
        $this->rechargeSubscriptionId = $rechargeSubscriptionId;
        $this->prescriptionId = $prescriptionId;
    }

    /**
     * Execute the job.
     *
     * @param RechargeService $rechargeService
     * @return void
     */
    public function handle(RechargeService $rechargeService): void
    {
        Log::info(
            "Executing CancelSubscriptionJob for Recharge Subscription ID: {$this->rechargeSubscriptionId}, Prescription ID: {$this->prescriptionId}"
        );

        $localSubscription = Subscription::where(
            "recharge_subscription_id",
            $this->rechargeSubscriptionId
        )->first();

        if (!$localSubscription) {
            Log::warning(
                "CancelSubscriptionJob: Local subscription not found for Recharge ID {$this->rechargeSubscriptionId}. Prescription ID {$this->prescriptionId}. Job will fail."
            );
            $this->fail(
                "Local subscription not found for Recharge ID {$this->rechargeSubscriptionId}."
            );
            return;
        }

        // Check if subscription is already cancelled to prevent redundant API calls/errors
        if ($localSubscription->status === "cancelled") {
            Log::info(
                "CancelSubscriptionJob: Subscription {$localSubscription->id} (Recharge ID {$this->rechargeSubscriptionId}) is already cancelled. Skipping."
            );
            return;
        }

        $prescription = Prescription::find($this->prescriptionId);
        if (!$prescription) {
            Log::warning(
                "CancelSubscriptionJob: Prescription ID {$this->prescriptionId} not found for Recharge Subscription ID {$this->rechargeSubscriptionId}. Job will fail."
            );
            // Potentially still attempt to cancel Recharge subscription if local prescription is gone but subscription exists.
            // For now, failing as it indicates a data integrity issue.
            $this->fail("Prescription ID {$this->prescriptionId} not found.");
            return;
        }

        DB::beginTransaction();
        try {
            $cancellationReason =
                "Prescription course completed or no refills remaining.";
            $cancellationNotes = "Automated cancellation: Prescription #{$this->prescriptionId} has no further doses scheduled or refills available.";

            $rechargeCancelled = $rechargeService->cancelSubscription(
                $this->rechargeSubscriptionId,
                $cancellationReason,
                $cancellationNotes
            );

            if ($rechargeCancelled) {
                // Log::info(
                //     "Successfully cancelled subscription in Recharge for ID: {$this->rechargeSubscriptionId}"
                // );

                // Update local subscription status
                $localSubscription->status = "cancelled";
                $localSubscription->save();

                // Update local prescription status if it's not already cancelled/completed
                if (
                    !in_array($prescription->status, [
                        "cancelled",
                        "completed",
                        "replaced",
                    ])
                ) {
                    $prescription->status = "cancelled";
                    $prescription->save();
                    // Log::info(
                    //     "Updated local prescription #{$prescription->id} status to cancelled."
                    // );
                }
                DB::commit();
                Log::info(
                    "CancelSubscriptionJob completed successfully for Recharge Subscription ID: {$this->rechargeSubscriptionId}."
                );
            } else {
                DB::rollBack();
                Log::error(
                    "CancelSubscriptionJob: Failed to cancel subscription in Recharge for ID: {$this->rechargeSubscriptionId}. Releasing job."
                );
                $this->release(60); // Retry in 1 minute
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error(
                "Exception in CancelSubscriptionJob for Recharge Subscription ID: {$this->rechargeSubscriptionId}",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            $this->release(60 * 2); // Retry in 2 minutes on generic exception
        }
    }
}
