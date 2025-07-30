<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Subscription;
use App\Services\ChargebeeService;

class SubscriptionController extends Controller
{
    protected $cacheDuration = 60 * 10; // 10 minutes
    protected $chargebeeService;

    public function __construct(ChargebeeService $chargebeeService)
    {
        $this->chargebeeService = $chargebeeService;
    }

    protected function getCacheKey($type = "subscriptions", $identifier = null)
    {
        $userId = auth()->id();
        $key = "chargebee_{$type}_{$userId}";

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

        // If not in our database, fetch from Chargebee API
        $subscriptions = $this->fetchSubscriptionsFromChargebee($user);

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
            $prescriptionId,
        )->first();

        if ($subscription && $subscription->chargebee_subscription_id) {
            $chargebeeSubscriptionId = $subscription->chargebee_subscription_id;

            // Get the latest data from Chargebee API
            $chargebeeSubscription = $this->chargebeeService->getSubscription(
                $chargebeeSubscriptionId,
            );

            if ($chargebeeSubscription) {
                return response()->json($chargebeeSubscription);
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
            $prescriptionId,
        )->first();

        if ($subscription && $subscription->chargebee_subscription_id) {
            $chargebeeSubscriptionId = $subscription->chargebee_subscription_id;

            // Begin DB transaction
            DB::beginTransaction();

            try {
                // Cancel using the service
                $success = $this->chargebeeService->cancelSubscription(
                    $chargebeeSubscriptionId,
                    $validated["reason"],
                );

                if (!$success) {
                    throw new \Exception("Failed to cancel subscription");
                }

                // Update the subscription status to cancelled
                $subscription->update(["status" => "cancelled"]);

                // Also cancel the prescription
                $prescription->update(["status" => "cancelled"]);

                // Commit the transaction
                DB::commit();

                // Remove the user's subscriptions from the Cache
                Cache::forget($this->getCacheKey("subscriptions"));

                return response()->json([
                    "message" => "Subscription cancelled successfully",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error(
                    "Error cancelling subscription: " . $e->getMessage(),
                );
                return response()->json(
                    [
                        "message" => "Failed to cancel subscription",
                    ],
                    500,
                );
            }
        } else {
            Cache::forget($this->getCacheKey("subscriptions"));
            return response()->json(
                [
                    "message" =>
                        "No active subscription found for this prescription",
                ],
                404,
            );
        }
    }

    /**
     * Fetch subscriptions from Chargebee API
     *
     * @param \App\Models\User $user The user to fetch subscriptions for
     * @return array Array of subscription data
     */
    private function fetchSubscriptionsFromChargebee($user)
    {
        $result = [];

        try {
            // Get the Chargebee customer ID based on user email
            $customerId = $this->getChargebeeCustomerId($user);

            if (!$customerId) {
                return $result;
            }

            // Get all subscriptions for this customer
            $subscriptions = $this->chargebeeService->getSubscriptionsForCustomer(
                $customerId,
            );

            if (empty($subscriptions)) {
                return $result;
            }

            return $subscriptions;
        } catch (\Exception $e) {
            Log::error(
                "Exception while fetching subscriptions from Chargebee",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ],
            );
            return $result;
        }
    }

    /**
     * Generate a Chargebee customer ID from user email
     *
     * @param \App\Models\User $user
     * @return string
     */
    private function getChargebeeCustomerId($user): string
    {
        // Use email as base for customer ID, making it Chargebee-friendly
        $customerId = preg_replace("/[^a-zA-Z0-9_-]/", "_", $user->email);
        return substr($customerId, 0, 50); // Chargebee has length limits
    }
}
