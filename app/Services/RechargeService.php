<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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
     * Update the product variant for a Recharge subscription.
     *
     * @param string $rechargeSubscriptionId The ID of the subscription in Recharge.
     * @param string $newVariantShopifyIdNumeric The numeric Shopify product variant ID for the new dose.
     * @param string|null $productTitle Optional new product title.
     * @param string|null $variantTitle Optional new variant title.
     * @return bool True if the update was successful, false otherwise.
     */
    public function updateSubscriptionVariant(
        string $rechargeSubscriptionId,
        string $newVariantShopifyIdNumeric,
        ?string $productTitle = null,
        ?string $variantTitle = null
    ): bool {
        if (empty($this->apiKey)) {
            Log::error(
                "Recharge API key is not configured. Cannot update subscription variant."
            );
            return false;
        }

        $payload = [
            "external_variant_id" => [
                "ecommerce" => $newVariantShopifyIdNumeric,
            ],
            "use_external_variant_defaults" => true, // To pull price & other defaults from Shopify variant
        ];

        if ($productTitle) {
            $payload["product_title"] = $productTitle;
        }
        if ($variantTitle) {
            $payload["variant_title"] = $variantTitle;
        }
        // If SKU needs to be explicitly set and override Shopify's, add 'sku' and 'sku_override: true'
        // For now, relying on use_external_variant_defaults to also fetch SKU.

        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
                "X-Recharge-Version" => "2021-11",
            ])->put(
                "{$this->endpoint}/subscriptions/{$rechargeSubscriptionId}",
                $payload
            );

            if ($response->successful()) {
                Log::info(
                    "Successfully updated Recharge subscription variant.",
                    [
                        "recharge_subscription_id" => $rechargeSubscriptionId,
                        "new_variant_shopify_id" => $newVariantShopifyIdNumeric,
                        "response_status" => $response->status(),
                    ]
                );
                return true;
            } else {
                Log::error("Failed to update Recharge subscription variant.", [
                    "recharge_subscription_id" => $rechargeSubscriptionId,
                    "new_variant_shopify_id" => $newVariantShopifyIdNumeric,
                    "payload_sent" => $payload,
                    "response_status" => $response->status(),
                    "response_body" => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception updating Recharge subscription variant.", [
                "recharge_subscription_id" => $rechargeSubscriptionId,
                "new_variant_shopify_id" => $newVariantShopifyIdNumeric,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return false;
        }
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
     * Update the next order date for a specific Recharge subscription.
     *
     * @param string $rechargeSubscriptionId The ID of the subscription in Recharge.
     * @param string $nextOrderDateStr The new next charge date in 'Y-m-d' format.
     * @return bool True if the update was successful, false otherwise.
     */
    public function updateNextOrderDate(
        string $rechargeSubscriptionId,
        string $nextOrderDateStr
    ): bool {
        if (empty($this->apiKey)) {
            Log::error(
                "Recharge API key is not configured. Cannot update next order date."
            );
            return false;
        }

        if (empty($rechargeSubscriptionId)) {
            Log::error(
                "Recharge Subscription ID is required to update next order date."
            );
            return false;
        }

        $payload = [
            "next_charge_scheduled_at" => $nextOrderDateStr,
        ];

        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
                "X-Recharge-Version" => "2021-11",
            ])->put(
                "{$this->endpoint}/subscriptions/{$rechargeSubscriptionId}",
                $payload
            );

            if ($response->successful()) {
                Log::info(
                    "Successfully updated next_charge_scheduled_at for Recharge subscription.",
                    [
                        "recharge_subscription_id" => $rechargeSubscriptionId,
                        "next_charge_scheduled_at" => $nextOrderDateStr,
                        "response_status" => $response->status(),
                    ]
                );
                return true;
            } else {
                Log::error(
                    "Failed to update next_charge_scheduled_at for Recharge subscription.",
                    [
                        "recharge_subscription_id" => $rechargeSubscriptionId,
                        "payload_sent" => $payload,
                        "response_status" => $response->status(),
                        "response_body" => $response->body(),
                    ]
                );
                return false;
            }
        } catch (\Exception $e) {
            Log::error(
                "Exception updating next_charge_scheduled_at for Recharge subscription.",
                [
                    "recharge_subscription_id" => $rechargeSubscriptionId,
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            return false;
        }
    }

    /**
     * Get subscriptions with renewals in the next X days.
     *
     * @param int $daysOut The number of days to look ahead for renewals.
     * @return array
     */
    public function getUpcomingRenewals(int $daysOut = 2): array
    {
        try {
            $startDate = Carbon::today();
            $endDate = Carbon::today()->addDays($daysOut);
            $upcomingRenewals = [];
            $url = "{$this->endpoint}/subscriptions";
            $params = [
                "status" => "ACTIVE",
                "limit" => 250,
            ];

            do {
                $response = Http::withHeaders([
                    "X-Recharge-Access-Token" => $this->apiKey,
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                ])->get($url, $params);

                if (!$response->successful()) {
                    Log::error(
                        "Failed to retrieve active subscriptions from Recharge for upcoming renewals check",
                        [
                            "status_code" => $response->status(),
                            "response" => $response->json(),
                        ]
                    );
                    break;
                }

                $currentSubscriptions =
                    $response->json()["subscriptions"] ?? [];

                // Filter for subscriptions renewing within the specified date range
                foreach ($currentSubscriptions as $subscription) {
                    $nextChargeDate = Carbon::parse(
                        $subscription["next_charge_scheduled_at"] ?? null
                    );

                    if ($nextChargeDate->between($startDate, $endDate)) {
                        $upcomingRenewals[] = $subscription;
                    }
                }

                // Get next page cursor
                $linkHeader = $response->header("Link");
                $url = null;
                $params = [];

                if ($linkHeader) {
                    preg_match(
                        '/<([^>]*)>;\s*rel="next"/',
                        $linkHeader,
                        $matches
                    );
                    if (isset($matches[1])) {
                        $url = $matches[1];
                    }
                }
            } while ($url !== null);

            return $upcomingRenewals;
        } catch (\Exception $e) {
            Log::error("Exception while fetching upcoming renewals", [
                "error" => $e->getMessage(),
                "days_out" => $daysOut,
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

    /**
     * Get all active subscriptions from Recharge API
     *
     * @param int $limit Maximum number of subscriptions to retrieve
     * @return array Array of subscription data
     */
    public function getAllActiveSubscriptions(int $limit = 100): array
    {
        try {
            $allSubscriptions = [];
            $url = "{$this->endpoint}/subscriptions";
            $params = [
                "status" => "ACTIVE",
                "limit" => min($limit, 250), // Recharge allows up to 250 per request
            ];

            do {
                // Make the API request
                $response = Http::withHeaders([
                    "X-Recharge-Access-Token" => $this->apiKey,
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                ])->get($url, $params);

                if (!$response->successful()) {
                    Log::error(
                        "Failed to retrieve active subscriptions from Recharge",
                        [
                            "status_code" => $response->status(),
                            "response" => $response->json(),
                        ]
                    );
                    break;
                }

                // Add the current page of results to our collection
                $currentSubscriptions =
                    $response->json()["subscriptions"] ?? [];
                $allSubscriptions = array_merge(
                    $allSubscriptions,
                    $currentSubscriptions
                );

                // Check for Link header which contains the cursor for the next page
                $linkHeader = $response->header("Link");

                // Reset URL and params for the next iteration
                $url = null;
                $params = [];

                // Parse Link header to get the next cursor URL if it exists
                if ($linkHeader) {
                    preg_match(
                        '/<([^>]*)>;\s*rel="next"/',
                        $linkHeader,
                        $matches
                    );
                    if (isset($matches[1])) {
                        $url = $matches[1]; // The full URL with cursor is in the Link header
                    }
                }

                // If we've reached the requested limit or there's no next cursor, exit the loop
                if (count($allSubscriptions) >= $limit || $url === null) {
                    break;
                }
            } while ($url !== null);

            return $allSubscriptions;
        } catch (\Exception $e) {
            Log::error(
                "Exception while fetching active subscriptions from Recharge",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            return [];
        }
    }

    /**
     * Get the first order for a subscription
     *
     * @param string $subscriptionId The Recharge subscription ID
     * @return array|null The first order data or null if not found
     */
    public function getFirstOrderForSubscription(string $subscriptionId): ?array
    {
        try {
            // Get first order for this subscription, limit to 1 and sort by created_at
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/orders?sort_by=created_at-asc", [
                "subscription_id" => $subscriptionId,
                "limit" => 1,
            ]);

            if (!$response->successful()) {
                Log::error(
                    "Failed to retrieve orders for subscription from Recharge",
                    [
                        "subscription_id" => $subscriptionId,
                        "status_code" => $response->status(),
                        "response" => $response->json(),
                    ]
                );
                return null;
            }

            $orders = $response->json()["orders"] ?? [];

            // Return the first order if available
            return !empty($orders) ? $orders[0] : null;
        } catch (\Exception $e) {
            Log::error(
                "Exception while fetching orders for subscription from Recharge",
                [
                    "subscription_id" => $subscriptionId,
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            return null;
        }
    }
}
