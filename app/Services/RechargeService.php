<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RechargeService
{
    protected $endpoint;
    protected $apiKey;

    public function __construct()
    {
        $this->endpoint = config("services.recharge.endpoint");
        $this->apiKey = config("services.recharge.api_key");
    }

    /**
     * Get customer by Shopify customer ID
     */
    public function getCustomerByShopifyId($shopifyCustomerId)
    {
        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/customers", [
                "shopify_customer_id" => $shopifyCustomerId,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $customers = $response->json()["customers"] ?? [];

            if (empty($customers)) {
                return null;
            }

            return $customers[0];
        } catch (\Exception $e) {
            Log::error("Error fetching customer by Shopify ID", [
                "error" => $e->getMessage(),
                "shopify_id" => $shopifyCustomerId,
            ]);
            return null;
        }
    }

    /**
     * Get subscriptions for a Recharge customer ID
     */
    public function getSubscriptionsForCustomerId($customerId)
    {
        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/subscriptions", [
                "customer_id" => $customerId,
            ]);

            if (!$response->successful()) {
                return [];
            }

            return $response->json()["subscriptions"] ?? [];
        } catch (\Exception $e) {
            Log::error("Error fetching subscriptions for customer", [
                "error" => $e->getMessage(),
                "customer_id" => $customerId,
            ]);
            return [];
        }
    }

    /**
     * Get a subscription by Recharge subscription ID
     */
    public function getSubscriptionByRechargeId($rechargeSubscriptionId)
    {
        // First try to get it from our database
        $subscription = Subscription::where(
            "recharge_subscription_id",
            $rechargeSubscriptionId
        )->first();

        if ($subscription) {
            return $subscription;
        }

        // If not found, try to get it from Recharge API
        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get(
                "{$this->endpoint}/subscriptions/{$rechargeSubscriptionId}"
            );

            if (!$response->successful()) {
                return null;
            }

            $subscriptionData = $response->json()["subscription"] ?? null;
            if (!$subscriptionData) {
                return null;
            }

            // Try to match with a user by email
            $email = $subscriptionData["email"] ?? null;
            if (!$email) {
                return null;
            }

            $user = \App\Models\User::where("email", $email)->first();
            if (!$user) {
                return null;
            }

            // Store this subscription in our database
            return $this->storeSubscription($subscriptionData, $user->id);
        } catch (\Exception $e) {
            Log::error("Error retrieving subscription from Recharge API", [
                "error" => $e->getMessage(),
                "subscription_id" => $rechargeSubscriptionId,
            ]);
            return null;
        }
    }

    /**
     * Cancel subscription and process refund when questionnaire is rejected
     */
    public function cancelSubscriptionForRejectedQuestionnaire(
        QuestionnaireSubmission $submission,
        string $reason
    ): bool {
        try {
            $patient = $submission->user;
            $success = false;

            // First, try to find subscription directly from our database
            $subscription = Subscription::where(
                "questionnaire_submission_id",
                $submission->id
            )->first();

            // If we found a subscription linked to this questionnaire
            if ($subscription && $subscription->status === "active") {
                // Log::info("Found active subscription to cancel", [
                //     "subscription_id" => $subscription->id,
                //     "recharge_id" => $subscription->recharge_subscription_id,
                //     "shopify_order_id" =>
                //         $subscription->original_shopify_order_id,
                // ]);

                // Handle Shopify order cancellation first if we have the order ID
                $shopifyOrderCancelled = false;
                if (!empty($subscription->original_shopify_order_id)) {
                    $shopifyService = app(ShopifyService::class);
                    $shopifyOrderCancelled = $shopifyService->cancelAndRefundOrder(
                        $subscription->original_shopify_order_id,
                        "Questionnaire rejected by healthcare provider: "
                    );

                    Log::info("Shopify order cancellation attempt", [
                        "order_id" => $subscription->original_shopify_order_id,
                        "success" => $shopifyOrderCancelled,
                    ]);
                }

                // Cancel the Recharge subscription if we have the ID
                $rechargeSubscriptionCancelled = false;
                if (!empty($subscription->recharge_subscription_id)) {
                    $rechargeSubscriptionCancelled = $this->cancelSubscription(
                        $subscription->recharge_subscription_id,
                        $reason,
                        "Questionnaire rejected: " . $submission->review_notes
                    );

                    Log::info("Recharge subscription cancellation attempt", [
                        "recharge_id" =>
                            $subscription->recharge_subscription_id,
                        "success" => $rechargeSubscriptionCancelled,
                    ]);
                }

                // Update our local subscription record if cancelled
                if ($shopifyOrderCancelled && $rechargeSubscriptionCancelled) {
                    $subscription->update([
                        "status" => "cancelled",
                    ]);
                }

                // Return true if both worked
                return $shopifyOrderCancelled && $rechargeSubscriptionCancelled;
            } else {
                // If we don't have a local subscription record, try to find the customer in Recharge
                // and cancel any active subscriptions

                // Try to get Shopify customer ID if available
                $shopifyCustomerId = $patient->shopify_customer_id ?? null;

                if ($shopifyCustomerId) {
                    // Get the Recharge customer by Shopify ID
                    $rechargeCustomer = $this->getCustomerByShopifyId(
                        $shopifyCustomerId
                    );

                    if ($rechargeCustomer) {
                        $rechargeCustomerId = $rechargeCustomer["id"];

                        // Get all subscriptions for this customer
                        $subscriptions = $this->getSubscriptionsForCustomerId(
                            $rechargeCustomerId
                        );

                        if (!empty($subscriptions)) {
                            $cancelledCount = 0;

                            foreach ($subscriptions as $subData) {
                                if ($subData["status"] === "ACTIVE") {
                                    $rechargeSubId = $subData["id"];

                                    // Cancel the subscription
                                    $cancelled = $this->cancelSubscription(
                                        $rechargeSubId,
                                        $reason,
                                        "Questionnaire rejected by healthcare provider"
                                    );

                                    if ($cancelled) {
                                        $cancelledCount++;

                                        // Store this subscription in our database
                                        $this->storeSubscription(
                                            $subData,
                                            $patient->id,
                                            $submission->id
                                        );
                                    }
                                }
                            }

                            if ($cancelledCount > 0) {
                                Log::info(
                                    "Cancelled $cancelledCount subscriptions found in Recharge for customer",
                                    [
                                        "user_id" => $patient->id,
                                        "shopify_customer_id" => $shopifyCustomerId,
                                        "recharge_customer_id" => $rechargeCustomerId,
                                    ]
                                );
                                $success = true;
                            }
                        }
                    }
                }
            }

            return $success;
        } catch (\Exception $e) {
            Log::error(
                "Exception while cancelling subscription for rejected questionnaire",
                [
                    "error" => $e->getMessage(),
                    "submission_id" => $submission->id,
                    "user_id" => $submission->user_id,
                ]
            );
            return false;
        }
    }

    /**
     * Update the next order date for a customer's active subscriptions
     */
    public function updateNextOrderDate(
        string $shopifyCustomerId,
        string $nextOrderDate
    ): bool {
        try {
            // Extract numeric part if needed
            if (strpos($shopifyCustomerId, "gid://") === 0) {
                $parts = explode("/", $shopifyCustomerId);
                $shopifyCustomerId = end($parts);
            }

            // Get the Recharge customer
            $customerResponse = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/customers", [
                "shopify_customer_id" => $shopifyCustomerId,
            ]);

            if (!$customerResponse->successful()) {
                return false;
            }

            $customers = $customerResponse->json()["customers"] ?? [];
            if (empty($customers)) {
                return false;
            }

            $customerId = $customers[0]["id"];

            // Get all subscriptions for this customer
            $subscriptionsResponse = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/subscriptions", [
                "customer_id" => $customerId,
            ]);

            if (!$subscriptionsResponse->successful()) {
                return false;
            }

            $subscriptions =
                $subscriptionsResponse->json()["subscriptions"] ?? [];

            // Find active subscriptions
            $activeSubscriptions = [];
            foreach ($subscriptions as $subscription) {
                if ($subscription["status"] === "ACTIVE") {
                    $activeSubscriptions[] = $subscription;
                }
            }

            if (empty($activeSubscriptions)) {
                return false;
            }

            // Update each active subscription
            $allUpdated = true;
            foreach ($activeSubscriptions as $subscription) {
                $updateData = [
                    "next_charge_scheduled_at" => $nextOrderDate,
                ];

                $updateResponse = Http::withHeaders([
                    "X-Recharge-Access-Token" => $this->apiKey,
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                ])->put(
                    "{$this->endpoint}/subscriptions/{$subscription["id"]}",
                    $updateData
                );

                if (!$updateResponse->successful()) {
                    $allUpdated = false;
                }
            }

            return $allUpdated;
        } catch (\Exception $e) {
            Log::error("Exception while updating next order date", [
                "error" => $e->getMessage(),
                "shopify_customer_id" => $shopifyCustomerId,
            ]);
            return false;
        }
    }

    /**
     * Get subscriptions with renewals in the next 24 hours
     */
    public function getUpcomingRenewals(): array
    {
        try {
            // Calculate tomorrow's date for renewals
            $tomorrow = Carbon::tomorrow()->format("Y-m-d");

            // Get subscriptions renewing tomorrow
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/subscriptions", [
                "status" => "ACTIVE",
                "next_charge_scheduled_at" => $tomorrow,
            ]);

            if (!$response->successful()) {
                return [];
            }

            $subscriptions = $response->json()["subscriptions"] ?? [];

            // Load customer details for each subscription
            foreach ($subscriptions as $key => $subscription) {
                $customerId = $subscription["customer_id"] ?? null;
                if ($customerId) {
                    $customerResponse = Http::withHeaders([
                        "X-Recharge-Access-Token" => $this->apiKey,
                        "Accept" => "application/json",
                        "Content-Type" => "application/json",
                    ])->get("{$this->endpoint}/customers/{$customerId}");

                    if ($customerResponse->successful()) {
                        $customer =
                            $customerResponse->json()["customer"] ?? null;
                        if ($customer) {
                            $subscriptions[$key]["customer"] = $customer;
                        }
                    }
                }
            }

            return $subscriptions;
        } catch (\Exception $e) {
            Log::error("Exception while fetching upcoming renewals", [
                "error" => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription(
        string $subscriptionId,
        string $reason,
        ?string $notes = null
    ): bool {
        try {
            // Prepare cancellation data
            $cancellationData = [
                "cancellation_reason" => $reason,
            ];

            if ($notes) {
                $cancellationData["cancellation_reason_comments"] = $notes;
            }

            // Cancel the subscription in Recharge
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->post(
                "{$this->endpoint}/subscriptions/{$subscriptionId}/cancel",
                $cancellationData
            );

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Exception while cancelling subscription in Recharge", [
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Store a subscription from Recharge API in our database
     */
    private function storeSubscription(
        $subscriptionData,
        $userId,
        $questionnaireSubmissionId = null
    ) {
        try {
            $rechargeSubId = $subscriptionData["id"];
            $customerId = $subscriptionData["customer_id"] ?? null;
            $productName = $subscriptionData["product_title"] ?? null;
            $shopifyProductId = $subscriptionData["shopify_product_id"] ?? null;
            $nextBillingDate =
                $subscriptionData["next_charge_scheduled_at"] ?? null;

            if (!$rechargeSubId || !$productName) {
                return null;
            }

            return Subscription::updateOrCreate(
                ["recharge_subscription_id" => $rechargeSubId],
                [
                    "recharge_customer_id" => $customerId,
                    "product_name" => $productName,
                    "shopify_product_id" => $shopifyProductId,
                    "user_id" => $userId,
                    "questionnaire_submission_id" => $questionnaireSubmissionId,
                    "next_billing_date" => $nextBillingDate,
                    "status" =>
                        $subscriptionData["status"] === "ACTIVE"
                            ? "active"
                            : ($subscriptionData["status"] === "CANCELLED"
                                ? "cancelled"
                                : "paused"),
                ]
            );
        } catch (\Exception $e) {
            Log::error("Error storing subscription in database", [
                "error" => $e->getMessage(),
                "subscription_id" => $subscriptionData["id"] ?? "unknown",
            ]);
            return null;
        }
    }
}
