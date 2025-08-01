<?php

namespace App\Http\Controllers;

use App\Config\ShopifyProductMapping;
use App\Models\QuestionnaireSubmission;
use App\Models\User;
use App\Notifications\ScheduleConsultationNotification;
use App\Notifications\MedicationDispatchedNotification;
use App\Services\CalendlyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\ProcessedRecurringOrder;
use App\Services\ChargebeeService;

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
            hash_hmac("sha256", $data, $secret, true),
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
                401,
            );
        }

        // Decode the webhook payload
        $payload = json_decode($data, true);

        // Determine if this is a consultation order
        $isConsultationOrder = false;
        if (isset($payload["line_items"]) && is_array($payload["line_items"])) {
            foreach ($payload["line_items"] as $item) {
                $productId = $item["product_id"];
                // Check if this numeric product id matches the numberic part of our consultation product GID
                $consultationProductId = ShopifyProductMapping::getConsultationProductId();
                if (
                    $consultationProductId &&
                    str_contains($consultationProductId, (string) $productId)
                ) {
                    $isConsultationOrder = true;
                    break;
                }
            }
        }

        if ($isConsultationOrder) {
            // Handle the consultation order
            $customerEmail = $payload["customer"]["email"] ?? null;
            $user = User::where("email", $customerEmail)->first();
            if (!$user) {
                Log::error("User not found for consultation order", [
                    "email" => $customerEmail,
                ]);
                return response()->json(
                    [
                        "message" => "User not found for consultation order",
                    ],
                    404,
                );
            }

            // Send a consultation link to the user
            $calendlyService = app(CalendlyService::class);
            try {
                $calendlyResult = $calendlyService->selectProviderAndGenerateLink(
                    $user,
                );

                if ($calendlyResult) {
                    $user->notify(
                        new ScheduleConsultationNotification(
                            null, // no  questionnaire submission for direct consultation purchase
                            $calendlyResult["provider"],
                            $calendlyResult["booking_url"],
                        ),
                    );
                } else {
                    Log::error("Failed to generate calendly link", [
                        "user_id" => $user->id,
                    ]);

                    // Return a failure response for webhook retrying
                    return response()->json(
                        [
                            "message" => "Failed to generate calendly link",
                        ],
                        500,
                    );
                }
            } catch (\Exception $e) {
                Log::error(
                    "Exception during direct consultation notification process: " .
                        $e->getMessage(),
                    [
                        "user_id" => $user->id,
                        "trace" => $e->getTraceAsString(),
                    ],
                );

                // Return a failure response for webhook retrying
                return response()->json(
                    [
                        "message" => "Failed to process consultation order",
                    ],
                    500,
                );
            }
            // Return success response after processing consultation order
            return response()->json([
                "message" => "Consultation order webhook processed",
            ]);
        }

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
                    400,
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
            hash_hmac("sha256", $data, $secret, true),
        );

        if (!hash_equals($hmacHeader, $calculatedHmac)) {
            Log::error(
                "Shopify orderFulfilled webhook HMAC verification failed",
            );
            return response()->json(
                ["message" => "Invalid webhook signature"],
                401,
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

        $chargebeeSubscriptionId = null;

        // Find subscription by Shopify order ID
        $subscription = Subscription::with(["user", "prescription"])
            ->where("latest_shopify_order_id", $shopifyOrderId)
            ->first();

        if (!$subscription) {
            Log::error("Subscription in order fulfilled webhook handler.", [
                "shopify_order_id" => $shopifyOrderId,
            ]);
            return response()->json(
                [
                    "message" => "Subscription not found",
                ],
                404,
            );
        }

        $chargebeeSubscriptionId = $subscription->chargebee_subscription_id;

        if (!$chargebeeSubscriptionId) {
            Log::error(
                "Could not determine Chargebee Subscription ID for fulfilled Shopify Order ID.",
                ["shopify_order_id" => $shopifyOrderId],
            );
            return response()->json(
                [
                    "message" =>
                        "No linked Chargebee subscription found. Can't update Chargebee subscription's next billing date.",
                ],
                404,
            );
        }

        $fulfillmentDate = isset($payload["fulfillments"][0]["created_at"])
            ? new \DateTime($payload["fulfillments"][0]["created_at"])
            : new \DateTime();

        // Update Chargebee subscription's next billing date to 28 days from fulfillment
        try {
            $chargebeeService = app(ChargebeeService::class);
            $nextBillingDate = $fulfillmentDate->modify("+28 days");

            $updateResult = $chargebeeService->changeSubscriptionTermEnd(
                $chargebeeSubscriptionId,
                $nextBillingDate->getTimestamp(),
            );

            if (!$updateResult) {
                Log::error("Failed to change Chargebee subscription term end", [
                    "shopify_order_id" => $shopifyOrderId,
                    "chargebee_subscription_id" => $chargebeeSubscriptionId,
                    "next_billing_date" => $nextBillingDate->format(
                        "Y-m-d H:i:s",
                    ),
                ]);
                return response()->json(
                    [
                        "error" =>
                            "Failed to change Chargebee subscription term end",
                    ],
                    500,
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to change Chargebee subscription term end", [
                "shopify_order_id" => $shopifyOrderId,
                "chargebee_subscription_id" => $chargebeeSubscriptionId,
                "error" => $e->getMessage(),
            ]);
        }

        // Send medication dispatched notification
        if ($subscription->prescription) {
            try {
                $subscription->user->notify(
                    new MedicationDispatchedNotification(
                        $subscription->prescription,
                    ),
                );
            } catch (\Exception $e) {
                Log::error(
                    "Failed to send medication dispatched notification",
                    [
                        "user_id" => $subscription->user->id,
                        "prescription_id" => $subscription->prescription->id,
                        "shopify_order_id" => $shopifyOrderId,
                        "error" => $e->getMessage(),
                    ],
                );
            }
        } else {
            Log::error("No prescription found for subscription", [
                "subscription_id" => $subscription->id,
                "shopify_order_id" => $shopifyOrderId,
            ]);
        }

        return response()->json([
            "message" => "Order fulfillment processed successfully.",
        ]);
    }
}
