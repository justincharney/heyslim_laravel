<?php

namespace App\Http\Controllers;

use App\Models\QuestionnaireSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\RechargeService;
use App\Models\Subscription;
use App\Models\ProcessedRecurringOrder;

class ShopifyWebhookController extends Controller
{
    /**
     * Handle the order paid webhook from Shopify.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function orderPaid(Request $request)
    {
        Log::info("Webhook function called", [
            "request_method" => $request->method(),
            "request_url" => $request->fullUrl(),
            "request_ip" => $request->ip(),
            "request_headers" => $request->headers->all(),
        ]);

        // Retrieve the raw request body
        $data = $request->getContent();

        // Get the HMAC header from Shopify
        $hmacHeader = $request->header("X-Shopify-Hmac-Sha256");

        // Use Shopify webhook secret stored in configuration
        $secret = config("services.shopify.webhook_secret");

        // Calculate the HMAC on the request data using the shared secret
        $calculatedHmac = base64_encode(
            hash_hmac("sha256", $data, $secret, true)
        );

        // Verify that the calculated HMAC matches the header provided by Shopify
        $hmacMatch = hash_equals($hmacHeader, $calculatedHmac);

        if (!$hmacMatch) {
            Log::error("Shopify webhook HMAC verification failed", [
                "header" => $hmacHeader,
                "calculated" => $calculatedHmac,
            ]);
            return response()->json(
                ["message" => "Invalid webhook signature"],
                401
            );
        }

        // Decode the webhook payload
        $payload = json_decode($data, true);

        // Update the status of the questionnaire submission
        // Attempt to retrieve custom questionnaire submission id from the order's note attributes
        $submissionId = null;
        if (
            isset($payload["note_attributes"]) &&
            is_array($payload["note_attributes"])
        ) {
            foreach ($payload["note_attributes"] as $attribute) {
                if ($attribute["name"] === "questionnaire_submission_id") {
                    $submissionId = $attribute["value"];
                    break;
                }
            }
        } else {
            Log::warning("No note_attributes found in payload or not in array");
        }

        if ($submissionId) {
            $submission = QuestionnaireSubmission::find($submissionId);
            if ($submission) {
                $submission->update(["status" => "submitted"]);
            } else {
                Log::error("Submission record not found in database", [
                    "submission_id" => $submissionId,
                ]);
                return response()->json(
                    [
                        "message" =>
                            "Submission record referenced in webhook payload not found",
                    ],
                    400
                );
            }

            // Return a successful response
            return response()->json([
                "message" => "Webhook processed successfully",
            ]);
        } else {
            // This can happen for subsequent orders after their first order that was made with the questionnaire (they subscribed)
            return response("No matching submission found", 200);
        }
    }

    /**
     * Handle order fulfillment webhook from Shopify.
     */
    public function orderFulfilled(Request $request)
    {
        Log::info("Shopify Order fulfillment webhook received");

        $data = $request->getContent();
        $hmacHeader = $request->header("X-Shopify-Hmac-Sha256");
        $secret = config("services.shopify.webhook_secret");
        $calculatedHmac = base64_encode(
            hash_hmac("sha256", $data, $secret, true)
        );

        if (!hash_equals($hmacHeader, $calculatedHmac)) {
            Log::error(
                "Shopify orderFulfilled webhook HMAC verification failed"
            );
            return response()->json(
                ["message" => "Invalid webhook signature"],
                401
            );
        }

        $payload = json_decode($data, true);
        $shopifyOrderId = $payload["id"] ?? null; // This is the Shopify numeric Order ID
        $fulfillmentStatus = $payload["fulfillment_status"] ?? null;

        if (!$shopifyOrderId || $fulfillmentStatus !== "fulfilled") {
            Log::info("Not a fulfillment event or order not fulfilled.", [
                "shopify_order_id" => $shopifyOrderId,
                "status" => $fulfillmentStatus,
            ]);
            return response()->json([
                "message" => "Not a fulfillment event or order not fulfilled",
            ]);
        }

        $rechargeSubscriptionId = null;

        // Strategy 1: Check if this Shopify Order ID is an original_shopify_order_id in our Subscription model
        $subscription = Subscription::where(
            "original_shopify_order_id",
            $shopifyOrderId
        )->first();
        if ($subscription) {
            $rechargeSubscriptionId = $subscription->recharge_subscription_id;
            Log::info(
                "Found Recharge subscription ID via original_shopify_order_id.",
                [
                    "shopify_order_id" => $shopifyOrderId,
                    "recharge_subscription_id" => $rechargeSubscriptionId,
                ]
            );
        } else {
            // Strategy 2: Check ProcessedRecurringOrder table (for recurring orders)
            // The $shopifyOrderId here is the Shopify numeric ID of the *current* transaction.
            $processedOrder = ProcessedRecurringOrder::where(
                "shopify_order_id",
                $shopifyOrderId
            )->first();
            if ($processedOrder && $processedOrder->prescription_id) {
                $relatedSubscription = Subscription::where(
                    "prescription_id",
                    $processedOrder->prescription_id
                )->first();
                if ($relatedSubscription) {
                    $rechargeSubscriptionId =
                        $relatedSubscription->recharge_subscription_id;
                    Log::info(
                        "Found Recharge subscription ID via ProcessedRecurringOrder.",
                        [
                            "shopify_order_id" => $shopifyOrderId,
                            "prescription_id" =>
                                $processedOrder->prescription_id,
                            "recharge_subscription_id" => $rechargeSubscriptionId,
                        ]
                    );
                }
            }
        }

        if (!$rechargeSubscriptionId) {
            Log::warning(
                "Could not determine Recharge Subscription ID for fulfilled Shopify Order ID.",
                ["shopify_order_id" => $shopifyOrderId]
            );
            // Decide if this is an error or just an order not linked to a Recharge sub we manage this way.
            // For now, we'll return success as the webhook was valid, but no action taken on Recharge.
            return response()->json(
                [
                    "message" =>
                        "Fulfilled order processed, but no linked Recharge subscription found to update next charge date.",
                ],
                200
            );
        }

        // Calculate the next order date (e.g., 1 month from fulfillment)
        $fulfillmentDate = isset($payload["fulfillments"][0]["created_at"])
            ? new \DateTime($payload["fulfillments"][0]["created_at"])
            : new \DateTime(); // Fallback to now if created_at is missing

        $nextOrderDate = clone $fulfillmentDate;
        $nextOrderDate->modify("+1 months");
        $nextOrderDateStr = $nextOrderDate->format("Y-m-d");

        $rechargeService = app(RechargeService::class);
        $updated = $rechargeService->updateNextOrderDate(
            $rechargeSubscriptionId,
            $nextOrderDateStr
        );

        if (!$updated) {
            Log::error(
                "Failed to update next_charge_scheduled_at in Recharge.",
                [
                    "recharge_subscription_id" => $rechargeSubscriptionId,
                    "next_date_calculated" => $nextOrderDateStr,
                ]
            );
            return response()->json(
                [
                    "message" =>
                        "Failed to update subscription next charge date in Recharge",
                ],
                500
            );
        }

        return response()->json([
            "message" =>
                "Order fulfillment processed successfully, Recharge next charge date updated.",
            "recharge_subscription_id" => $rechargeSubscriptionId,
            "next_charge_scheduled_at" => $nextOrderDateStr,
        ]);
    }
}
