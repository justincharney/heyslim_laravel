<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Subscription;
use App\Services\RechargeService;

class SubscriptionController extends Controller
{
    protected $cacheDuration = 60 * 10; // 10 minutes
    protected $rechargeService;

    public function __construct(RechargeService $rechargeService)
    {
        $this->rechargeService = $rechargeService;
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
        $cacheKey = $this->getCacheKey("subscriptions");

        // Try to get subscriptions from cache first
        if (Cache::has($cacheKey)) {
            return response()->json([
                "subscriptions" => Cache::get($cacheKey),
            ]);
        }

        // Then try to get subscriptions from our database
        $localSubscriptions = Subscription::where("user_id", $user->id)->get();

        if ($localSubscriptions->isNotEmpty()) {
            // Cache the subscriptions
            Cache::put($cacheKey, $localSubscriptions, $this->cacheDuration);

            return response()->json([
                "subscriptions" => $localSubscriptions,
            ]);
        }

        // If not in our database, fetch from Recharge API
        $subscriptions = $this->fetchSubscriptionsFromRecharge($user);

        Cache::put($cacheKey, $subscriptions, $this->cacheDuration);

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

        // First check if we have the subscription in our database
        $subscription = Subscription::where(
            "prescription_id",
            $prescriptionId
        )->first();

        if ($subscription && $subscription->recharge_subscription_id) {
            $rechargeSubscriptionId = $subscription->recharge_subscription_id;

            // Get the latest data from Recharge API
            $rechargeSubscription = $this->rechargeService->getSubscriptionByRechargeId(
                $rechargeSubscriptionId
            );

            if ($rechargeSubscription) {
                return response()->json([
                    "subscription" => $rechargeSubscription,
                ]);
            }
        }

        // No subscription for prescription found. This shouldn't happen since we require a subscription to create a prescription
        return response()->json([
            "subscription" => $subscription,
        ]);
    }

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

        // First check if we have the subscription in our database
        $subscription = Subscription::where(
            "prescription_id",
            $prescriptionId
        )->first();

        if ($subscription && $subscription->recharge_subscription_id) {
            $rechargeSubscriptionId = $subscription->recharge_subscription_id;

            // Cancel using the service
            $success = $this->rechargeService->cancelSubscription(
                $rechargeSubscriptionId,
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

            // Remove the user's subscriptions from the Cache
            Cache::forget($this->getCacheKey("subscriptions"));

            return response()->json([
                "message" => "Subscription cancelled successfully",
            ]);
        } else {
            Cache::forget($this->getCacheKey("subscriptions"));
            return response()->json(
                [
                    "message" =>
                        "No active subscription found for this prescription",
                ],
                404
            );
        }
    }

    /**
     * Fetch subscriptions from Recharge API
     *
     * @param \App\Models\User $user The user to fetch subscriptions for
     * @return array Array of subscription data
     */
    private function fetchSubscriptionsFromRecharge($user)
    {
        $result = [];
        $shopifyCustomerId = $user->shopify_customer_id;

        if (!$shopifyCustomerId) {
            return $result;
        }

        // Use recharge_customer_id to optimize the API call
        // if ($user->recharge_customer_id) {
        //     // Get all subscriptions for this customer using recharge_customer_id
        //     $subscriptionsResponse = $this->rechargeService->getSubscriptionsForCustomerId(
        //         $user->recharge_customer_id
        //     );
        //     if (!empty($subscriptionsResponse)) {
        //         return $this->formatSubscriptions(
        //             $subscriptionsResponse,
        //             $user->id
        //         );
        //     }
        // }

        // Extract just the numeric part from the Shopify GID
        if (strpos($shopifyCustomerId, "gid://") === 0) {
            $parts = explode("/", $shopifyCustomerId);
            $shopifyCustomerId = end($parts);
        }

        try {
            // Get the recharge customer based on the shopify customer
            $customerResponse = $this->rechargeService->getCustomerByShopifyId(
                $shopifyCustomerId
            );

            if (empty($customerResponse)) {
                return $result;
            }

            $customerId = $customerResponse["id"];

            // Store the Recharge customer ID for future use
            // if (!$user->recharge_customer_id) {
            //     $user->update(["recharge_customer_id" => $customerId]);
            // }

            // Get all subscriptions for this customer
            $subscriptions = $this->rechargeService->getSubscriptionsForCustomerId(
                $customerId
            );

            if (empty($subscriptions)) {
                return $result;
            }

            return $subscriptions;
        } catch (\Exception $e) {
            Log::error("Exception while fetching subscriptions from Recharge", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return $result;
        }
    }
}
