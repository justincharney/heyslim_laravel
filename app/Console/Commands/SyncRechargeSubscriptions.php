<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\RechargeService;

class SyncRechargeSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:sync-recharge-subscriptions {--limit=100 : Maximum number of subscriptions to process}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Sync active subscriptions from Recharge to local database";

    protected $rechargeService;

    public function __construct(RechargeService $rechargeService)
    {
        parent::__construct();
        $this->rechargeService = $rechargeService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->option("limit");
        $this->info(
            "Starting to sync Recharge subscriptions (limit: {$limit})"
        );

        try {
            // Fetch all active subscriptions from Recharge
            $activeSubscriptions = $this->rechargeService->getAllActiveSubscriptions(
                $limit
            );

            if (empty($activeSubscriptions)) {
                $this->info("No active subscriptions found in Recharge.");
                return 0;
            }

            $this->info(
                "Found " .
                    count($activeSubscriptions) .
                    " active subscriptions in Recharge."
            );

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($activeSubscriptions as $subscription) {
                $rechargeSubId = $subscription["id"] ?? null;
                $shopifyCustomerId =
                    $subscription["shopify_customer_id"] ?? null;
                $email = $subscription["email"] ?? null;

                if (!$rechargeSubId || !$email) {
                    $this->warn(
                        "Missing required data for subscription. Skipping."
                    );
                    $skipped++;
                    continue;
                }

                // Check if the subscription already exists in our database
                $existingSubscription = Subscription::where(
                    "recharge_subscription_id",
                    $rechargeSubId
                )->first();

                if ($existingSubscription) {
                    $this->info(
                        "Subscription already exists: {$rechargeSubId}"
                    );
                    continue;
                    // Update the existing subscription
                    // try {
                    //     $this->updateExistingSubscription(
                    //         $existingSubscription,
                    //         $subscription
                    //     );
                    //     $updated++;
                    //     $this->info("Updated subscription: {$rechargeSubId}");
                    // } catch (\Exception $e) {
                    //     $this->error(
                    //         "Error updating subscription {$rechargeSubId}: " .
                    //             $e->getMessage()
                    //     );
                    //     Log::error(
                    //         "Error updating subscription from Recharge",
                    //         [
                    //             "subscription_id" => $rechargeSubId,
                    //             "error" => $e->getMessage(),
                    //         ]
                    //     );
                    //     $errors++;
                    // }
                } else {
                    // Create a new subscription record
                    try {
                        $userId = User::where("email", $email)->first()->id;

                        if ($userId) {
                            $subscription = $this->createNewSubscription(
                                $subscription,
                                $userId
                            );
                            if ($subscription) {
                                $created++;
                                $this->info(
                                    "Created new subscription: {$rechargeSubId}"
                                );
                            } else {
                                $this->error(
                                    "Failed to create new subscription: {$rechargeSubId}"
                                );
                                $errors++;
                            }
                        } else {
                            $this->warn(
                                "Could not find or create user for email: {$email}. Skipping."
                            );
                            $skipped++;
                        }
                    } catch (\Exception $e) {
                        $this->error(
                            "Error creating subscription {$rechargeSubId}: " .
                                $e->getMessage()
                        );
                        Log::error(
                            "Error creating subscription from Recharge",
                            [
                                "subscription_id" => $rechargeSubId,
                                "error" => $e->getMessage(),
                            ]
                        );
                        $errors++;
                    }
                }
            }

            $this->info(
                "Sync completed: {$created} created, {$updated} updated, {$skipped} skipped, {$errors} errors"
            );
            return 0;
        } catch (\Exception $e) {
            $this->error(
                "Error syncing Recharge subscriptions: " . $e->getMessage()
            );
            Log::error("Error in SyncRechargeSubscriptions command", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * Create a new subscription record
     */
    private function createNewSubscription(
        array $subscriptionData,
        int $userId
    ): ?Subscription {
        $rechargeSubId = $subscriptionData["id"];
        $customerId = $subscriptionData["customer_id"] ?? null;
        $productTitle = $subscriptionData["product_title"] ?? "Unknown Product";
        $shopifyProductId = $subscriptionData["shopify_product_id"] ?? null;
        $nextChargeScheduledAt =
            $subscriptionData["next_charge_scheduled_at"] ?? null;
        $status =
            $subscriptionData["status"] === "ACTIVE" ? "active" : "paused";

        // Get the first order associated with this subscription
        $firstOrder = $this->rechargeService->getFirstOrderForSubscription(
            $rechargeSubId
        );

        $originalShopifyOrderId = $firstOrder["shopify_order_id"] ?? null;
        $questionnaireSubId = null;
        $noteAttributes = $firstOrder["note_attributes"] ?? [];
        foreach ($noteAttributes as $attribute) {
            if ($attribute["name"] === "questionnaire_submission_id") {
                $questionnaireSubId = $attribute["value"];
                break;
            }
        }

        if ($originalShopifyOrderId && $questionnaireSubId) {
            $this->info(
                "Found original order for subscription {$rechargeSubId}: " .
                    ($originalShopifyOrderId ?? "No Shopify Order ID")
            );
        } else {
            $this->info(
                "No original order found for subscription {$rechargeSubId}"
            );
            return null;
        }

        return Subscription::create([
            "recharge_subscription_id" => $rechargeSubId,
            "recharge_customer_id" => $customerId,
            "shopify_product_id" => $shopifyProductId,
            "product_name" => $productTitle,
            "status" => $status,
            "next_charge_scheduled_at" => $nextChargeScheduledAt,
            "original_shopify_order_id" => $originalShopifyOrderId,
            "user_id" => $userId,
            "questionnaire_submission_id" => $questionnaireSubId,
        ]);
    }

    // /**
    //  * Update an existing subscription with new data
    //  */
    // private function updateExistingSubscription(
    //     Subscription $subscription,
    //     array $subscriptionData
    // ): void {
    //     $nextChargeScheduledAt =
    //         $subscriptionData["next_charge_scheduled_at"] ?? null;
    //     $status =
    //         $subscriptionData["status"] === "ACTIVE"
    //             ? "active"
    //             : ($subscriptionData["status"] === "CANCELLED"
    //                 ? "cancelled"
    //                 : "paused");

    //     $subscription->update([
    //         "recharge_customer_id" =>
    //             $subscriptionData["customer_id"] ??
    //             $subscription->recharge_customer_id,
    //         "product_name" =>
    //             $subscriptionData["product_title"] ??
    //             $subscription->product_name,
    //         "shopify_product_id" =>
    //             $subscriptionData["shopify_product_id"] ??
    //             $subscription->shopify_product_id,
    //         "status" => $status,
    //         "next_charge_scheduled_at" => $nextChargeScheduledAt,
    //     ]);
    // }
}
