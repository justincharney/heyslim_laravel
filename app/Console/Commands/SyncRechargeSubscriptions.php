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
                    // $this->info(
                    //     "Subscription already exists: {$rechargeSubId}"
                    // );
                    // continue;

                    //Update the existing subscription
                    try {
                        $this->updateExistingSubscription(
                            $existingSubscription,
                            $subscription
                        );
                        $updated++;
                        $this->info("Updated subscription: {$rechargeSubId}");
                    } catch (\Exception $e) {
                        $this->error(
                            "Error updating subscription {$rechargeSubId}: " .
                                $e->getMessage()
                        );
                        Log::error(
                            "Error updating subscription from Recharge",
                            [
                                "subscription_id" => $rechargeSubId,
                                "error" => $e->getMessage(),
                            ]
                        );
                        $errors++;
                    }
                } else {
                    // Create a new subscription record
                    // Use updateOrCreate to prevent race conditions
                    try {
                        $userId = User::where("email", $email)->first()?->id;

                        if (!$userId) {
                            $this->warn(
                                "Could not find user for email: {$email}. Skipping."
                            );
                            $skipped++;
                            continue;
                        }

                        // Get first order data for new subscriptions only
                        $firstOrder = null;
                        $originalShopifyOrderId = null;
                        $questionnaireSubId = null;

                        // Check if this subscription already exists to avoid unnecessary API calls
                        $existingSubscription = Subscription::where(
                            "recharge_subscription_id",
                            $rechargeSubId
                        )->first();

                        if (!$existingSubscription) {
                            // Only fetch first order data for new subscriptions
                            $firstOrder = $this->rechargeService->getFirstOrderForSubscription(
                                $rechargeSubId
                            );
                            $originalShopifyOrderId =
                                $firstOrder["shopify_order_id"] ?? null;
                            $noteAttributes =
                                $firstOrder["note_attributes"] ?? [];
                            foreach ($noteAttributes as $attribute) {
                                if (
                                    $attribute["name"] ===
                                    "questionnaire_submission_id"
                                ) {
                                    $questionnaireSubId = $attribute["value"];
                                    break;
                                }
                            }

                            // Skip if we can't find required order data for new subscriptions
                            if (
                                !$originalShopifyOrderId ||
                                !$questionnaireSubId
                            ) {
                                $this->warn(
                                    "Could not find required order data for new subscription {$rechargeSubId}. Skipping."
                                );
                                $skipped++;
                                continue;
                            }
                        }

                        $subscriptionRecord = Subscription::updateOrCreate(
                            ["recharge_subscription_id" => $rechargeSubId],
                            array_filter([
                                "recharge_customer_id" =>
                                    $subscription["customer_id"] ?? null,
                                "shopify_product_id" =>
                                    $subscription["shopify_product_id"] ?? null,
                                "product_name" =>
                                    $subscription["product_title"] ??
                                    "Unknown Product",
                                "status" =>
                                    $subscription["status"] === "ACTIVE"
                                        ? "active"
                                        : "paused",
                                "next_charge_scheduled_at" =>
                                    $subscription["next_charge_scheduled_at"] ??
                                    null,
                                "user_id" => $userId,
                                // Only set these for new subscriptions
                                "original_shopify_order_id" => $existingSubscription
                                    ? $existingSubscription->original_shopify_order_id
                                    : $originalShopifyOrderId,
                                "questionnaire_submission_id" => $existingSubscription
                                    ? $existingSubscription->questionnaire_submission_id
                                    : $questionnaireSubId,
                            ])
                        );

                        if ($subscriptionRecord->wasRecentlyCreated) {
                            $created++;
                            $this->info(
                                "Created new subscription: {$rechargeSubId}"
                            );
                        } else {
                            $updated++;
                            $this->info(
                                "Updated subscription: {$rechargeSubId}"
                            );
                        }
                    } catch (\Exception $e) {
                        $this->error(
                            "Error processing subscription {$rechargeSubId}: " .
                                $e->getMessage()
                        );
                        Log::error(
                            "Error processing subscription from Recharge",
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

    /**
     * Update an existing subscription with new data
     */
    private function updateExistingSubscription(
        Subscription $subscription,
        array $subscriptionData
    ): void {
        $nextChargeScheduledAt =
            $subscriptionData["next_charge_scheduled_at"] ?? null;
        $status =
            $subscriptionData["status"] === "ACTIVE"
                ? "active"
                : ($subscriptionData["status"] === "CANCELLED"
                    ? "cancelled"
                    : "paused");

        $subscription->update([
            "status" => $status,
            "next_charge_scheduled_at" => $nextChargeScheduledAt,
        ]);
    }
}
