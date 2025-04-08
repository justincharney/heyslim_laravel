<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\User;
use App\Services\RechargeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
            $shopifyCustomerId =
                $renewal["customer"]["shopify_customer_id"] ?? null;
            $subscriptionId = $renewal["id"] ?? null;
            $productTitle = $renewal["product_title"] ?? "Unknown Product";

            if (!$shopifyCustomerId || !$subscriptionId) {
                $this->warn(
                    "Missing customer ID or subscription ID for renewal."
                );
                continue;
            }

            // Find the user by Shopify customer ID
            $user = User::where(
                "shopify_customer_id",
                $shopifyCustomerId
            )->first();

            if (!$user) {
                $this->warn(
                    "No user found for Shopify customer ID: " .
                        $shopifyCustomerId
                );
                continue;
            }

            // Extract medication name from the product title (removes variant info)
            $medicationName = preg_replace('/\s+\(.*?\)$/', "", $productTitle);

            // Check if the user has a matching active prescription
            $matchingPrescription = Prescription::where("patient_id", $user->id)
                ->where("status", "active")
                ->where("end_date", ">=", Carbon::now())
                ->where(function ($query) use ($medicationName) {
                    // Try to match by medication name, looking for partial matches
                    // This handles cases like "Mounjaro" in the subscription matching
                    // "Tirzepatide (Mounjaro)" in the prescription
                    $query
                        ->where(
                            "medication_name",
                            "like",
                            "%{$medicationName}%"
                        )
                        ->orWhere(function ($q) use ($medicationName) {
                            // Handle the reverse case - medication name in prescription
                            // might be shorter than product title
                            $q->whereRaw(
                                "? LIKE CONCAT('%', medication_name, '%')",
                                [$medicationName]
                            );
                        });
                })
                ->first();

            if (!$matchingPrescription) {
                $this->info(
                    "User {$user->id} has no matching active prescription for {$productTitle}. Cancelling subscription {$subscriptionId}."
                );

                // Cancel the subscription
                $cancelled = $rechargeService->cancelSubscription(
                    $subscriptionId,
                    "No active matching prescription",
                    "System automatically cancelled subscription due to no active prescription found for {$productTitle}."
                );

                if ($cancelled) {
                    $cancelCount++;
                    Log::info(
                        "Cancelled subscription due to no matching active prescription",
                        [
                            "user_id" => $user->id,
                            "subscription_id" => $subscriptionId,
                            "product_title" => $productTitle,
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
                    "User {$user->id} has an active prescription for {$productTitle}. Subscription valid."
                );
            }
        }

        $this->info(
            "Validation complete. Valid: {$validCount}. Cancelled: {$cancelCount}."
        );
    }
}
