<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
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
     * Cancel subscription and process refund when questionnaire is rejected
     */
    public function cancelSubscriptionForRejectedQuestionnaire(
        QuestionnaireSubmission $submission,
        string $reason
    ): bool {
        $patient = $submission->user;
        $shopifyCustomerId = $patient->shopify_customer_id;

        if (!$shopifyCustomerId) {
            Log::warning(
                "Cannot cancel subscription - no Shopify customer ID found for user {$patient->id}"
            );
            return false;
        }

        // Extract numeric part from Shopify GID if needed
        if (strpos($shopifyCustomerId, "gid://") === 0) {
            $parts = explode("/", $shopifyCustomerId);
            $shopifyCustomerId = end($parts);
        }

        try {
            // Get the Recharge customer
            $customerResponse = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiKey,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("{$this->endpoint}/customers", [
                "shopify_customer_id" => $shopifyCustomerId,
            ]);

            if (!$customerResponse->successful()) {
                Log::error("Failed to fetch customer from Recharge API", [
                    "status" => $customerResponse->status(),
                    "response" => $customerResponse->json(),
                ]);
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

            // Get the selected product from the questionnaire submission
            $productInfo = null;
            $productAnswer = $submission
                ->answers()
                ->whereHas("question", function ($query) {
                    $query->where("label", "Treatment Selection");
                })
                ->first();

            if ($productAnswer) {
                $productInfo = $productAnswer->answer_text;
            }

            // Figure out which subscription(s) to cancel
            $subscriptionsToCancelIds = [];

            // If we could identify the specific product from the questionnaire
            if ($productInfo) {
                // Extract product name (e.g., "Mounjaro" from "Mounjaro (£199.00)")
                $productName = explode(" (", $productInfo)[0];

                // Find subscriptions for this specific product
                foreach ($subscriptions as $subscription) {
                    if (
                        $subscription["status"] === "ACTIVE" &&
                        stripos(
                            $subscription["product_title"],
                            $productName
                        ) !== false
                    ) {
                        $subscriptionsToCancelIds[] = $subscription["id"];
                    }
                }
            }

            // If we couldn't identify specific subscription(s) by product, cancel all active ones
            // This is also a fallback if the above product matching didn't find any
            if (empty($subscriptionsToCancelIds)) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription["status"] === "ACTIVE") {
                        $subscriptionsToCancelIds[] = $subscription["id"];
                    }
                }
            }

            if (empty($subscriptionsToCancelIds)) {
                Log::warning(
                    "No active subscriptions found to cancel for rejected questionnaire",
                    [
                        "submission_id" => $submission->id,
                        "patient_id" => $patient->id,
                    ]
                );
                return false;
            }

            // Cancel all identified subscriptions
            $allCancelled = true;
            foreach ($subscriptionsToCancelIds as $subscriptionId) {
                // Cancel the subscription
                $cancellationData = [
                    "cancellation_reason" => $reason,
                    "cancellation_reason_comments" =>
                        "Questionnaire rejected: " . $submission->review_notes,
                ];

                $cancelResponse = Http::withHeaders([
                    "X-Recharge-Access-Token" => $this->apiKey,
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                ])->post(
                    "{$this->endpoint}/subscriptions/{$subscriptionId}/cancel",
                    $cancellationData
                );

                if (!$cancelResponse->successful()) {
                    $allCancelled = false;
                    Log::error("Failed to cancel subscription", [
                        "subscription_id" => $subscriptionId,
                        "response" => $cancelResponse->json(),
                    ]);
                } else {
                    Log::info(
                        "Subscription cancelled due to rejected questionnaire",
                        [
                            "subscription_id" => $subscriptionId,
                            "questionnaire_id" => $submission->id,
                        ]
                    );
                }
            }

            return $allCancelled;
        } catch (\Exception $e) {
            Log::error("Exception while cancelling subscription", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
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
}
