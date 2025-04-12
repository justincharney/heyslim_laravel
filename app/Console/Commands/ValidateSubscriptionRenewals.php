<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\User;
use App\Services\RechargeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;

class ValidateSubscriptionRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:validate-subscription-renewals";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Check upcoming subscription renewals and cancel those without valid prescriptions";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting subscription renewal validation...");

        // Get upcoming renewals in the next 24 hours
        $rechargeService = app(RechargeService::class);
        $upcomingRenewals = $rechargeService->getUpcomingRenewals();

        if (empty($upcomingRenewals)) {
            $this->info("No upcoming renewals found.");
            return;
        }

        $this->info(
            "Found " . count($upcomingRenewals) . " upcoming renewals."
        );

        $cancelCount = 0;
        $validCount = 0;

        foreach ($upcomingRenewals as $renewal) {
            $subscriptionId = $renewal["id"] ?? null;
            $productTitle = $renewal["product_title"] ?? "Unknown Product";

            if (!$subscriptionId) {
                $this->warn("Missing subscription ID for renewal.");
                continue;
            }

            // Find the internal subscription record that corresponds to this Recharge subscription
            $subscription = Subscription::where(
                "recharge_subscription_id",
                $subscriptionId
            )->first();

            if (!$subscription) {
                $this->warn(
                    "No internal subscription record found for Recharge subscription ID: {$subscriptionId}"
                );
                continue;
            }

            // Get the associated prescription using the relationship
            $prescription = $subscription->prescription;

            if (!$prescription) {
                $this->info(
                    "Subscription {$subscriptionId} has no associated prescription. Cancelling subscription."
                );

                // Cancel the subscription
                $cancelled = $rechargeService->cancelSubscription(
                    $subscriptionId,
                    "No associated prescription",
                    "System automatically cancelled subscription due to no prescription found."
                );

                if ($cancelled) {
                    $cancelCount++;
                    Log::info(
                        "Cancelled subscription due to no associated prescription",
                        [
                            "subscription_id" => $subscriptionId,
                            "product_title" => $productTitle,
                        ]
                    );
                } else {
                    $this->error(
                        "Failed to cancel subscription {$subscriptionId}"
                    );
                }

                continue;
            }

            // Check if the prescription is active and not expired
            if (
                $prescription->status !== "active" ||
                Carbon::parse($prescription->end_date)->isPast() ||
                $prescription->refills <= 0
            ) {
                $reason = "Unknown issue";
                if ($prescription->status !== "active") {
                    $reason = "Prescription is not active";
                } elseif (Carbon::parse($prescription->end_date)->isPast()) {
                    $reason = "Prescription has expired";
                } elseif ($prescription->refills <= 0) {
                    $reason = "No refills remaining";
                }

                $this->info(
                    "Subscription {$subscriptionId} has an invalid prescription: {$reason}. Cancelling subscription."
                );

                // Cancel the subscription
                $cancelled = $rechargeService->cancelSubscription(
                    $subscriptionId,
                    $reason,
                    "System automatically cancelled subscription due to {$reason}."
                );

                if ($cancelled) {
                    $cancelCount++;
                    Log::info(
                        "Cancelled subscription due to invalid prescription",
                        [
                            "subscription_id" => $subscriptionId,
                            "prescription_id" => $prescription->id,
                            "reason" => $reason,
                        ]
                    );
                } else {
                    $this->error(
                        "Failed to cancel subscription {$subscriptionId}"
                    );
                }
            } else {
                $validCount++;
                $this->info(
                    "Subscription {$subscriptionId} has a valid prescription with {$prescription->refills} refills remaining. Subscription valid."
                );
            }
        }

        $this->info(
            "Validation complete. Valid: {$validCount}. Cancelled: {$cancelCount}."
        );
    }
}
