<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SubscriptionController extends Controller
{
    protected $endpoint;
    protected $apiKey;
    protected $cacheDuration = 60 * 10; // 10 minutes

    public function __construct()
    {
        $this->endpoint = config("services.recharge.endpoint");
        $this->apiKey = config("services.recharge.api_key");
    }

    protected function getCacheKey($type = "subscriptions", $identifier = null)
    {
        $userId = auth()->id();
        $key = "recharge_{$type}_{$userId}";

        if ($identifier) {
            $key .= "_{$identifier}";
        }

        return $key;
    }

    public function index()
    {
        $user = auth()->user();

        // Get all prescriptions for the user
        $prescriptions = $user
            ->prescriptionsAsPatient()
            ->where("status", "active")
            ->get();

        // Fetch subscription data from Recharge API
        $subscriptions = $this->fetchSubscriptionsFromRecharge();

        return response()->json([
            "subscriptions" => $subscriptions,
        ]);
    }

    public function show($prescriptionId)
    {
        $user = auth()->user();

        // Verify the prescription belongs to the user
        $prescription = $user
            ->prescriptionsAsPatient()
            ->findOrFail($prescriptionId);

        // Fetch subscription data from Recharge API
        $subscription = $this->getSubscriptionForPrescription($prescriptionId);

        return response()->json([
            "subscription" => $subscription,
        ]);
    }

    // public function pause(Request $request, $prescriptionId)
    // {
    //     $user = auth()->user();

    //     $validated = $request->validate([
    //         "pause_duration" => "required|integer|min:1|max:12",
    //     ]);

    //     // Verify the prescription belongs to the user
    //     $prescription = $user
    //         ->prescriptionsAsPatient()
    //         ->findOrFail($prescriptionId);

    //     // Calculate resume date
    //     $resumeDate = now()
    //         ->addMonths($validated["pause_duration"])
    //         ->format("Y-m-d");

    //     // Pause subscription in Recharge API
    //     $response = $this->pauseSubscriptionInRecharge(
    //         $prescriptionId,
    //         $resumeDate
    //     );

    //     // Log the pause
    //     Log::info(
    //         "Subscription for prescription #$prescriptionId paused by patient #$user->id. Resume date: $resumeDate"
    //     );

    //     return response()->json([
    //         "message" => "Subscription paused successfully",
    //         "resume_date" => $resumeDate,
    //     ]);
    // }

    // public function resume($prescriptionId)
    // {
    //     $user = auth()->user();

    //     // Verify the prescription belongs to the user
    //     $prescription = $user
    //         ->prescriptionsAsPatient()
    //         ->findOrFail($prescriptionId);

    //     // Resume subscription in Recharge API
    //     $response = $this->resumeSubscriptionInRecharge($prescriptionId);

    //     // Log the resume
    //     Log::info(
    //         "Subscription for prescription #$prescriptionId resumed by patient #$user->id"
    //     );

    //     return response()->json([
    //         "message" => "Subscription resumed successfully",
    //     ]);
    // }

    public function cancel(Request $request, $prescriptionId)
    {
        $user = auth()->user();

        $validated = $request->validate([
            "reason" => "required|string",
            "notes" => "nullable|string",
        ]);

        // Verify the prescription belongs to the user
        $prescription = $user
            ->prescriptionsAsPatient()
            ->findOrFail($prescriptionId);

        // Get the subscription for this prescription
        $subscription = $this->getSubscriptionForPrescription($prescriptionId);

        if (!$subscription) {
            return response()->json(
                [
                    "message" =>
                        "No active subscription found for this prescription",
                ],
                404
            );
        }

        // Cancel subscription in Recharge API
        $success = $this->cancelSubscriptionInRecharge(
            $subscription["id"],
            $validated["reason"],
            $validated["notes"]
        );

        if (!$success) {
            return response()->json(
                [
                    "message" => "Failed to cancel subscription",
                ],
                500
            );
        }

        // Update the prescription status
        $prescription->update(["status" => "cancelled"]);

        Cache::forget($this->getCacheKey("subscriptions"));

        // Log the cancellation
        Log::info(
            "Subscription for prescription #$prescriptionId cancelled by patient #$user->id. Reason: {$validated["reason"]}"
        );

        return response()->json([
            "message" => "Subscription cancelled successfully",
        ]);
    }

    /**
     * Fetch subscriptions from Recharge API
     *
     * @return array Array of subscription data
     */
    private function fetchSubscriptionsFromRecharge()
    {
        $result = [];
        $shopifyCustomerId = auth()->user()->shopify_customer_id;
        $cacheKey = $this->getCacheKey("subscriptions");

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (!$shopifyCustomerId) {
            Log::warning(
                "No Shopify customer ID found for user " . auth()->id()
            );
            return $result;
        }

        // Extract just the numeric part from the Shopify GID
        // format like "gid://shopify/Customer/1234567890"
        if (strpos($shopifyCustomerId, "gid://") === 0) {
            $parts = explode("/", $shopifyCustomerId);
            $shopifyCustomerId = end($parts); // Get the numeric ID at the end
        }

        try {
            // Get the recharge customer based on the shopify customer
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
                return $result;
            }

            $customers = $customerResponse->json()["customers"] ?? [];

            // Check if customers array exists and has at least one customer
            if (empty($customers)) {
                Log::warning(
                    "No customers found in Recharge for Shopify customer ID: $shopifyCustomerId"
                );
                return $result;
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
                Log::error("Failed to fetch subscriptions from Recharge API", [
                    "status" => $subscriptionsResponse->status(),
                    "response" => $subscriptionsResponse->json(),
                ]);
                return $result;
            }

            $subscriptions =
                $subscriptionsResponse->json()["subscriptions"] ?? [];

            // Map Recharge subscription data to our format
            foreach ($subscriptions as $subscription) {
                $result[] = [
                    "id" => $subscription["id"],
                    "status" => $subscription["status"],
                    "next_charge_date" =>
                        $subscription["next_charge_scheduled_at"],
                    "frequency" =>
                        $subscription["order_interval_frequency"] .
                        " " .
                        $subscription["order_interval_unit"],
                    "price" => $subscription["price"],
                    "product_title" => $subscription["product_title"],
                    "shopify_product_id" => $subscription["shopify_product_id"],
                    "shopify_variant_id" => $subscription["shopify_variant_id"],
                    "variant_title" => $subscription["variant_title"],
                    "created_at" => $subscription["created_at"],
                    "updated_at" => $subscription["updated_at"],
                    "cancelled_at" => $subscription["cancelled_at"],
                ];
            }

            Cache::put($cacheKey, $result, $this->cacheDuration);
            return $result;
        } catch (\Exception $e) {
            Log::error("Exception while fetching subscriptions from Recharge", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return $result;
        }
    }
    /**
     * Get the Recharge subscription ID for a prescription
     *
     * @param int $prescriptionId
     * @return array|null
     */
    private function getSubscriptionForPrescription($prescriptionId)
    {
        // Get the prescription with its related questionnaire submission
        $prescription = Prescription::with(
            "clinicalPlan.questionnaireSubmission.answers.question"
        )->find($prescriptionId);

        if (
            !$prescription ||
            !$prescription->clinicalPlan ||
            !$prescription->clinicalPlan->questionnaireSubmission
        ) {
            Log::warning(
                "No questionnaire submission found for prescription ID: $prescriptionId"
            );
            return null;
        }

        // Find the treatment selection answer
        $treatmentSelection = null;
        $submission = $prescription->clinicalPlan->questionnaireSubmission;

        foreach ($submission->answers as $answer) {
            if (
                $answer->question &&
                $answer->question->label == "Treatment Selection"
            ) {
                $treatmentSelection = $answer->answer_text;
                break;
            }
        }

        if (!$treatmentSelection) {
            return null;
        }

        // Extract the medication name (e.g., "Mounjaro" from "Mounjaro (£199.00)")
        $medicationName = explode(" (", $treatmentSelection)[0];

        // Get all subscriptions
        $subscriptions = $this->fetchSubscriptionsFromRecharge();

        // Find the subscription with matching product title
        foreach ($subscriptions as $subscription) {
            // If the product title contains the medication name
            if (
                stripos($subscription["product_title"], $medicationName) !==
                false
            ) {
                // And the subscription is active
                if ($subscription["status"] === "ACTIVE") {
                    return $subscription;
                }
            }
        }

        Log::warning(
            "No matching subscription found for medication: $medicationName"
        );
        return null;
    }

    /**
     * Cancel a subscription in Recharge
     *
     * @param int $subscriptionId The Recharge subscription ID
     * @param string $reason The cancellation reason
     * @param string|null $notes Additional notes
     * @return bool Success status
     */
    protected function cancelSubscriptionInRecharge(
        $subscriptionId,
        $reason,
        $notes = null
    ) {
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

            if (!$response->successful()) {
                Log::error("Failed to cancel subscription in Recharge API", [
                    "subscription_id" => $subscriptionId,
                    "status" => $response->status(),
                    "response" => $response->json(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Exception while cancelling subscription in Recharge", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}
