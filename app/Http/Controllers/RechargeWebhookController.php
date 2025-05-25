<?php

namespace App\Http\Controllers;

use App\Jobs\AttachInitialLabelToShopifyJob;
use App\Jobs\InitiateYousignSignatureJob;
use App\Jobs\ProcessSignedPrescriptionJob;
use App\Jobs\ProcessRecurringOrderAttachmentsJob;
use App\Models\Questionnaire;
use App\Models\QuestionnaireSubmission;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\ShopifyService;
use App\Services\YousignService;
use App\Models\ProcessedRecurringOrder;

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
        $order = $data["order"] ?? null;

        if (!$order) {
            Log::error("Recharge orderCreated webhook missing order data.");
            return response()->json(["error" => "Missing order data"], 400);
        }

        $orderType = $order["type"] ?? null;
        $originalShopifyOrderId = $order["shopify_order_id"] ?? null;
        $lineItems = $order["line_items"] ?? [];
        $email = $order["email"] ?? null;
        $noteAttributes = $order["note_attributes"] ?? []; // Get note attributes

        if (empty($lineItems) || !$originalShopifyOrderId) {
            Log::error(
                "Recharge order webhook missing critical data (line_items or shopify_order_id)",
                [
                    "order_id_recharge" => $order["id"] ?? "N/A",
                    "original_shopify_order_id" => $originalShopifyOrderId,
                ]
            );
            return response()->json(
                ["error" => "Missing line items or Shopify Order ID"],
                400
            );
        }

        $firstItem = $lineItems[0];
        $rechargeSubId = $firstItem["subscription_id"] ?? null;

        // Find the subscription record
        $localSubscription = Subscription::where(
            "recharge_subscription_id",
            $rechargeSubId
        )->first();

        // Log::info("details", [
        //     "order_id" => $originalShopifyOrderId,
        //     "subscription_id" => $rechargeSubId,
        // ]);

        // SCENARIO 1: RECURRING order (decrement refills)
        if ($localSubscription && $orderType === "RECURRING") {
            // This is a renewal order
            try {
                // Find associated prescription
                $prescription = $localSubscription->prescription;

                if ($prescription) {
                    // Log::info("Order/Prescription info", [
                    //     "order_id" => $originalShopifyOrderId,
                    //     "subscription_id" => $rechargeSubId,
                    //     "prescription_id" => $prescription->id,
                    //     "current_refills" => $prescription->refills,
                    //     "new_refills" => $prescription->refills - 1,
                    // ]);
                    // Check if we've already processed this prescription
                    $alreadyProcesssed = ProcessedRecurringOrder::where(
                        "shopify_order_id",
                        $originalShopifyOrderId
                    )
                        ->where("prescription_id", $prescription->id)
                        ->exists();

                    if ($alreadyProcesssed) {
                        Log::info(
                            "Recurring order already processed for refill decrement.",
                            [
                                "shopify_order_id" => $originalShopifyOrderId,
                                "prescription_id" => $prescription->id,
                            ]
                        );
                        return response()->json(
                            ["message" => "Order already processed"],
                            200
                        );
                    }

                    // Only decrement if we have refills remaining
                    if ($prescription->refills <= 0) {
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

                    // Start DB transaction - ONLY for database operations
                    DB::beginTransaction();
                    try {
                        // Reload prescription inside transaction with lock
                        $prescription = Prescription::lockForUpdate()->findOrFail(
                            $prescription->id
                        );

                        // Check refills again inside transaction (just to be safe)
                        if ($prescription->refills > 0) {
                            // Decrement and save
                            $prescription->refills -= 1;
                            $prescription->save();

                            // Record that this order event processed a refill
                            ProcessedRecurringOrder::create([
                                "shopify_order_id" => $originalShopifyOrderId,
                                "prescription_id" => $prescription->id,
                                "processed_at" => now(),
                            ]);

                            // COMMIT TRANSACTION BEFORE dispatching job
                            DB::commit();

                            // Dispatch job to process attachments
                            ProcessRecurringOrderAttachmentsJob::dispatch(
                                $prescription->id,
                                $originalShopifyOrderId // Shopify order ID of the new transaction
                            );
                            Log::info(
                                "Dispatched attachment job for prescription #{$prescription->id} and order {$originalShopifyOrderId}"
                            );

                            return response()->json(
                                [
                                    "message" =>
                                        "Renewal order processed, attachment job dispatched",
                                ],
                                200
                            );
                        } else {
                            // No refills left now (race condition)
                            DB::rollBack();
                            Log::warning(
                                "Renewal order received but prescription has no refills remaining (race condition)",
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
                    } catch (\Exception $dbError) {
                        DB::rollBack();
                        Log::error(
                            "Error decrementing prescription refills for renewal order",
                            [
                                "order_id" => $originalShopifyOrderId,
                                "subscription_id" => $rechargeSubId,
                                "prescription_id" => $prescription->id,
                                "current_refills" => $prescription->refills,
                                "error_message" => $dbError->getMessage(),
                            ]
                        );
                        return response()->json(
                            [
                                "message" =>
                                    "Error decrementing prescription refills: " .
                                    $dbError->getMessage(),
                            ],
                            500
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
        if ($rechargeSubId && $orderType === "CHECKOUT") {
            // Get the prescription id associated with the subscription
            $prescriptionIdFromAttribute = null;
            foreach ($noteAttributes as $attribute) {
                if ($attribute["name"] === "prescription_id") {
                    $prescriptionIdFromAttribute = $attribute["value"];
                    break;
                }
            }

            if (!$prescriptionIdFromAttribute) {
                Log::warning(
                    "CHECKOUT order from Recharge is missing 'prescription_id' note attribute. Cannot reliably link to prescription.",
                    [
                        "recharge_subscription_id" => $rechargeSubId,
                        "shopify_order_id" => $originalShopifyOrderId,
                    ]
                );
                return response()->json(
                    [
                        "message" => "Prescription ID not found",
                    ],
                    400
                );
            }

            // Start a database transaction
            DB::beginTransaction();

            try {
                // Find user by email
                $user = User::where("email", $email)->first();
                if (!$user) {
                    Log::error("User not found from email in Recharge order", [
                        "email" => $email,
                    ]);
                    DB::rollBack();
                    return response()->json(["error" => "User not found"], 404);
                }

                // Create or update the subscription - related SUBMISSION SHOULD BE UPDATED IN BOOT METHOD
                $subscription = Subscription::updateOrCreate(
                    ["recharge_subscription_id" => $rechargeSubId],
                    [
                        "recharge_customer_id" => $order["customer_id"],
                        "shopify_product_id" =>
                            $firstItem["shopify_product_id"],
                        "original_shopify_order_id" => $originalShopifyOrderId,
                        "product_name" => $firstItem["product_title"],
                        "status" => "active",
                        "user_id" => $user->id,
                        "prescription_id" => $prescriptionIdFromAttribute,
                    ]
                );

                // Handle prescription attachment to the order
                if ($prescriptionIdFromAttribute) {
                    $prescription = Prescription::find(
                        $prescriptionIdFromAttribute
                    );
                    if ($prescription) {
                        if ($prescription->status === "pending_payment") {
                            $prescription->status = "pending_signature";
                            $prescription->save();
                        }

                        // JOB 1: Generate and attach prescription label to Shopify order
                        AttachInitialLabelToShopifyJob::dispatch(
                            $prescription->id,
                            $originalShopifyOrderId
                        );

                        // If prescription is already signed, dispatch job to attach signed PDF
                        if (
                            $prescription->yousign_document_id &&
                            $prescription->signed_at
                        ) {
                            // JOB 2: Process the signed prescription
                            ProcessSignedPrescriptionJob::dispatch(
                                $prescription->id,
                                $prescription->yousign_signature_request_id,
                                $prescription->yousign_document_id
                            );

                            // Update the status to 'active'
                            $prescription->status = "active";
                            $prescription->save();
                        }
                    } else {
                        Log::error(
                            "Prescription not found for ID from attribute.",
                            [
                                "prescription_id_attr" => $prescriptionIdFromAttribute,
                            ]
                        );
                        DB::rollBack();
                        return response()->json(
                            ["message" => "Prescription not found"],
                            404
                        );
                    }
                }

                // Commit the transaction
                DB::commit();

                // Return a success response
                return response()->json(
                    ["message" => "Checkout order processed successfully"],
                    200
                );
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
            400
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
