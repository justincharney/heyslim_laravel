<?php

namespace App\Http\Controllers;

use App\Models\Questionnaire;
use App\Models\QuestionnaireSubmission;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RechargeWebhookController extends Controller
{
    /**
     * Validate webhook signature from Recharge
     */
    private function validateWebhook(Request $request)
    {
        $hmacHeader = $request->header("X-Recharge-Hmac-Sha256");
        $secret = config("services.recharge.client_secret");
        $data = $request->getContent();

        $calculatedHmac = hash_hmac("sha256", $data, $secret);

        Log::debug("Webhook validation details", [
            "received_hmac" => $hmacHeader,
            "calculated_hmac" => $calculatedHmac,
            "content_length" => strlen($data),
            "secret_set" => !empty($secret),
            "match" => hash_equals($hmacHeader, $calculatedHmac),
        ]);

        return hash_equals($hmacHeader, $calculatedHmac);
    }

    /**
     * Handle order created webhook
     */
    public function orderCreated(Request $request)
    {
        // Log::info("orderCreated webhook", [
        //     "request_method" => $request->method(),
        //     "request_url" => $request->fullUrl(),
        //     "request_ip" => $request->ip(),
        //     "request_headers" => $request->headers->all(),
        //     "payload" => $request->getContent(),
        // ]);

        // if (!$this->validateWebhook($request)) {
        //     return response()->json(["error" => "Invalid signature"], 401);
        // }

        $data = json_decode($request->getContent(), true);
        // Log::info("orderCreated webhook", [
        //     "data" => $data,
        // ]);

        // Extract subscription information
        $order = $data["order"] ?? null;
        $orderType = $order["type"] ?? null;
        $shopifyCustomerId = $order["shopify_customer_id"] ?? null;
        $rechargeCustomerId = $order["customer_id"] ?? null;
        $email = $order["email"] ?? null;
        $originalShopifyOrderId = $order["shopify_order_id"] ?? null;

        // Extract order information
        $lineItems = $order["line_items"] ?? [];
        if (empty($lineItems)) {
            Log::error("Recharge order webhook missing line items", [
                "order_id" => $originalShopifyOrderId,
            ]);
            return response()->json(["error" => "Missing line items"], 400);
        }

        // Get the first line item (assumes one subscription per order)
        $firstItem = $lineItems[0];
        $rechargeSubId = $firstItem["subscription_id"] ?? null;
        $shopifyProductId = $firstItem["shopify_product_id"] ?? null;
        $productName = $firstItem["product_title"] ?? null;

        // Extract questionnaire submission ID from note attributes
        $questionnaireSubId = null;
        $noteAttributes = $order["note_attributes"] ?? [];
        foreach ($noteAttributes as $attribute) {
            if ($attribute["name"] === "questionnaire_submission_id") {
                $questionnaireSubId = $attribute["value"];
                break;
            }
        }

        // Find the subscription record
        $subscription = Subscription::where(
            "recharge_subscription_id",
            $rechargeSubId
        )->first();

        // SCENARIO 1: RECURRING order (decrement refills)
        if ($subscription && $orderType === "RECURRING") {
            // This is a renewal order
            try {
                // Find associated prescription
                $prescription = $subscription->prescription;

                if ($prescription) {
                    // Only decrement if we have refills remaining
                    if ($prescription->refills > 0) {
                        // Log::info(
                        //     "Decrementing prescription refills for renewal order",
                        //     [
                        //         "order_id" => $originalShopifyOrderId,
                        //         "subscription_id" => $rechargeSubId,
                        //         "prescription_id" => $prescription->id,
                        //         "current_refills" => $prescription->refills,
                        //         "new_refills" => $prescription->refills - 1,
                        //     ]
                        // );

                        // Decrement and save
                        $prescription->refills -= 1;
                        $prescription->save();

                        return response()->json(
                            [
                                "message" =>
                                    "Renewal order processed, prescription refills decremented",
                            ],
                            200
                        );
                    } else {
                        Log::warning(
                            "Renewal order received but prescription has no refills remaining",
                            [
                                "order_id" => $originalShopifyOrderId,
                                "subscription_id" => $rechargeSubId,
                                "prescription_id" => $prescription->id,
                            ]
                        );

                        return response()->json(
                            [
                                "message" =>
                                    "Warning: Prescription has no refills remaining",
                            ],
                            200
                        );
                    }
                } else {
                    Log::error("Subscription has no associated prescription", [
                        "subscription_id" => $subscription->id,
                        "order_id" => $originalShopifyOrderId,
                    ]);

                    return response()->json(
                        [
                            "error" =>
                                "Subscription has no associated prescription",
                        ],
                        404
                    );
                }
            } catch (\Exception $e) {
                Log::error("Error processing renewal order", [
                    "order_id" => $originalShopifyOrderId,
                    "error" => $e->getMessage(),
                ]);

                return response()->json(
                    [
                        "error" =>
                            "Error processing renewal order: " .
                            $e->getMessage(),
                    ],
                    500
                );
            }
        }

        // SCENARIO 2: CHECKOUT order (create or update subscription)
        if ($questionnaireSubId && $orderType === "CHECKOUT") {
            $submission = QuestionnaireSubmission::find($questionnaireSubId);
            if ($submission) {
                // Start a database transaction
                DB::beginTransaction();

                try {
                    // Find user by email
                    $user = User::where("email", $email)->first();

                    if (!$user) {
                        Log::error(
                            "User not found from email in Recharge order",
                            [
                                "email" => $email,
                            ]
                        );
                        return response()->json(
                            ["error" => "User not found"],
                            404
                        );
                    }

                    // Create or update the subscription
                    $subscription = Subscription::updateOrCreate(
                        ["recharge_subscription_id" => $rechargeSubId],
                        [
                            "recharge_customer_id" => $rechargeCustomerId,
                            "shopify_product_id" => $shopifyProductId,
                            "original_shopify_order_id" => $originalShopifyOrderId,
                            "product_name" => $productName,
                            "status" => "active",
                            "user_id" => $user->id,
                            "questionnaire_submission_id" => $questionnaireSubId,
                        ]
                    );
                    // Commit the transaction
                    DB::commit();
                } catch (\Exception $e) {
                    // Rollback the transaction in case of error
                    DB::rollback();

                    Log::error("Error processing Recharge order", [
                        "order_id" => $originalShopifyOrderId,
                        "error" => $e->getMessage(),
                    ]);
                    return response()->json(
                        ["error" => "Error processing order"],
                        500
                    );
                }
            } else {
                Log::error("Submission record not found in database", [
                    "submission_id" => $questionnaireSubId,
                ]);
                return response()->json(
                    ["error" => "Questionnaire submission not found"],
                    404
                );
            }
        }

        // SCENARIO 3: Unrecognized pattern
        Log::info(
            "No matching questionnaire submission or existing subscription found",
            [
                "order_id" => $originalShopifyOrderId,
                "subscription_id" => $rechargeSubId,
            ]
        );

        return response()->json(
            ["message" => "Order processed but no action taken"],
            200
        );
    }

    /**
     * Handle subscription cancelled webhook
     */
    public function subscriptionCancelled(Request $request)
    {
        // if (!$this->validateWebhook($request)) {
        //     return response()->json(["error" => "Invalid signature"], 401);
        // }

        // Log::info("subscriptionCancelled webhook", [
        //     "payload" => $request->getContent(),
        // ]);

        $data = json_decode($request->getContent(), true);

        // Extract subscription data
        $subscriptionData = $data["subscription"] ?? null;

        if (!$subscriptionData) {
            Log::error("Missing subscription data in cancelled webhook");
            return response()->json(["error" => "Invalid payload format"], 400);
        }

        // Extract subscription ID
        $rechargeSubId = $subscriptionData["id"] ?? null;

        if (!$rechargeSubId) {
            return response()->json(
                ["error" => "Missing subscription ID"],
                400
            );
        }

        // Find the subscription
        $subscription = Subscription::where(
            "recharge_subscription_id",
            $rechargeSubId
        )->first();

        if ($subscription) {
            DB::beginTransaction();
            try {
                // Update subscription status
                $subscription->update(["status" => "cancelled"]);

                // update linked prescription if exists
                if ($subscription->prescription_id) {
                    Prescription::where(
                        "id",
                        $subscription->prescription_id
                    )->update(["status" => "cancelled"]);
                }

                DB::commit();
                Log::info("Marked subscription as cancelled", [
                    "id" => $subscription->id,
                ]);
                return response()->json([
                    "message" => "Subscription cancelled successfully",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Error processing subscription cancellation", [
                    "error" => $e->getMessage(),
                ]);
                return response()->json(
                    ["error" => "Error processing cancellation"],
                    500
                );
            }
        } else {
            Log::warning("Subscription not found for cancellation", [
                "recharge_id" => $rechargeSubId,
            ]);
            return response()->json(["error" => "Subscription not found"], 404);
        }
    }

    /**
     * Handle subscription created webhook
     */
    public function subscriptionCreated(Request $request)
    {
        // if (!$this->validateWebhook($request)) {
        //     Log::error("Invalid Recharge webhook signature");
        //     return response()->json(["error" => "Invalid signature"], 401);
        // }

        $data = json_decode($request->getContent(), true);
        // Log::info("Recharge subscription created webhook", ["data" => $data]);

        // Extract the subscription object from the data
        $subscriptionData = $data["subscription"] ?? null;
        // Extract relevant information to update the existing subscription
        $rechargeSubId = $subscriptionData["id"] ?? null;
        $nextChargeScheduledAt =
            $subscriptionData["next_charge_scheduled_at"] ?? null;

        try {
            // Check if we already have a subscription record for this recharge subscription
            $subscription = Subscription::where(
                "recharge_subscription_id",
                $rechargeSubId
            )->first();

            // Create/update the subscription record
            $subscription = Subscription::updateOrCreate(
                ["recharge_subscription_id" => $rechargeSubId],
                [
                    "next_charge_scheduled_at" => $nextChargeScheduledAt,
                ]
            );

            return response()->json([
                "message" => "Webhook processed successfully",
            ]);
        } catch (\Exception $e) {
            Log::error("Error processing subscription created webhook", [
                "error" => $e->getMessage(),
                "data" => $data,
            ]);
            return response()->json(
                ["error" => "Error processing webhook"],
                500
            );
        }
    }
}
