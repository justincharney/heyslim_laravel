<?php

namespace App\Http\Controllers;

use App\Jobs\AttachLabelToShopifyJob;
use App\Jobs\CancelSubscriptionJob;
use App\Jobs\SkuSwapJob;
use App\Jobs\ProcessSignedPrescriptionJob;
use App\Services\RechargeService;
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
        $currentShopifyOrderId = $order["shopify_order_id"] ?? null;
        $lineItems = $order["line_items"] ?? [];
        $email = $order["email"] ?? null;
        $noteAttributes = $order["note_attributes"] ?? []; // Get note attributes

        if (empty($lineItems) || !$currentShopifyOrderId) {
            Log::error(
                "Recharge order webhook missing critical data (line_items or shopify_order_id)",
                [
                    "order_id_recharge" => $order["id"] ?? "N/A",
                    "original_shopify_order_id" => $currentShopifyOrderId,
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
        //     "order_id" => $currentShopifyOrderId,
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
                    //     "order_id" => $currentShopifyOrderId,
                    //     "subscription_id" => $rechargeSubId,
                    //     "prescription_id" => $prescription->id,
                    //     "current_refills" => $prescription->refills,
                    //     "new_refills" => $prescription->refills - 1,
                    // ]);
                    // Check if we've already processed this prescription
                    $alreadyProcesssed = ProcessedRecurringOrder::where(
                        "shopify_order_id",
                        $currentShopifyOrderId
                    )
                        ->where("prescription_id", $prescription->id)
                        ->exists();

                    if ($alreadyProcesssed) {
                        Log::info(
                            "Recurring order already processed for refill decrement.",
                            [
                                "shopify_order_id" => $currentShopifyOrderId,
                                "prescription_id" => $prescription->id,
                            ]
                        );
                        return response()->json(
                            ["message" => "Order already processed"],
                            200
                        );
                    }

                    // For replacement prescriptions, check if this is truly their first order ever
                    $isReplacement = !is_null(
                        $prescription->replaces_prescription_id
                    );
                    $shouldDecrementRefills = true;

                    if ($isReplacement) {
                        // Check if this replacement has been processed in any RECURRING order
                        $prescriptionEverProcessedInRecurring = ProcessedRecurringOrder::where(
                            "prescription_id",
                            $prescription->id
                        )->exists();

                        // Check if this replacement has been through CHECKOUT by looking at subscription
                        $hasHadCheckoutOrder =
                            $prescription->subscription &&
                            $prescription->subscription
                                ->original_shopify_order_id;

                        // If this replacement hasn't had any RECURRING orders AND hasn't had a CHECKOUT,
                        // then this RECURRING order is effectively its "initial dose"
                        if (
                            !$prescriptionEverProcessedInRecurring &&
                            !$hasHadCheckoutOrder
                        ) {
                            $shouldDecrementRefills = false;
                            Log::info(
                                "This is the first order ever for replacement prescription #{$prescription->id}. " .
                                    "Treating this RECURRING order as initial dose - not decrementing refills."
                            );
                        }
                    }

                    // Only decrement if we have refills remaining
                    if ($prescription->refills <= 0) {
                        Log::warning(
                            "Renewal order received but prescription has no refills remaining",
                            [
                                "order_id" => $currentShopifyOrderId,
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

                    $refill_decremented_successfully = false;
                    $updated_prescription_after_decrement = null;

                    // Start DB transaction - ONLY for database operations
                    DB::beginTransaction();
                    try {
                        // Reload prescription inside transaction with lock
                        $prescription = Prescription::lockForUpdate()->findOrFail(
                            $prescription->id
                        );

                        // For replacement prescription on their first RECURRING
                        if ($shouldDecrementRefills) {
                            // Check refills again inside transaction (just to be safe)
                            if ($prescription->refills > 0) {
                                // Decrement and save
                                $prescription->refills -= 1;
                                $prescription->save();
                            } else {
                                // No refills left now (race condition)
                                DB::rollBack();
                                Log::warning(
                                    "Renewal order received but prescription has no refills remaining (race condition)",
                                    [
                                        "order_id" => $currentShopifyOrderId,
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
                            Log::info(
                                "Skipped refill decrement for first-time replacement prescription #{$prescription->id}"
                            );
                        }
                    } catch (\Exception $dbError) {
                        DB::rollBack();
                        Log::error(
                            "Error decrementing prescription refills for renewal order",
                            [
                                "order_id" => $currentShopifyOrderId,
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

                    // Record that this order event processed a refill
                    ProcessedRecurringOrder::create([
                        "shopify_order_id" => $currentShopifyOrderId,
                        "prescription_id" => $prescription->id,
                        "processed_at" => now(),
                    ]);

                    // COMMIT TRANSACTION BEFORE dispatching job
                    DB::commit();
                    $refill_decremented_successfully = true;
                    $updated_prescription_after_decrement = $prescription->fresh();

                    if (
                        $refill_decremented_successfully &&
                        $updated_prescription_after_decrement
                    ) {
                        // Dispatch job to attach the label for the CURRENT recurring order
                        AttachLabelToShopifyJob::dispatch(
                            $updated_prescription_after_decrement->id,
                            $currentShopifyOrderId
                        );
                        Log::info(
                            "Dispatched AttachLabelToShopifyJob for RECURRING order prescription #{$updated_prescription_after_decrement->id} and order {$currentShopifyOrderId}"
                        );

                        // Handle Attaching the signed prescription to the recurring order
                        if (
                            !empty(
                                $updated_prescription_after_decrement->signed_prescription_supabase_path
                            )
                        ) {
                            ProcessSignedPrescriptionJob::dispatch(
                                $updated_prescription_after_decrement->id,
                                $currentShopifyOrderId
                            );
                            Log::info(
                                "Dispatched ProcessSignedPrescriptionJob for RECURRING order prescription #{$updated_prescription_after_decrement->id} and order {$currentShopifyOrderId}"
                            );
                        } else {
                            Log::warning(
                                "Prescription #{$updated_prescription_after_decrement->id} does not have a signed document available. Skipping attachment to recurring order {$currentShopifyOrderId}."
                            );
                        }

                        // Handle SKU swap for the NEXT ORDER
                        $dose_schedule =
                            $updated_prescription_after_decrement->dose_schedule;
                        $current_refills_remaining =
                            $updated_prescription_after_decrement->refills;

                        if (
                            !is_array($dose_schedule) ||
                            empty($dose_schedule)
                        ) {
                            Log::error(
                                "Dose schedule is empty or an invalid array for prescription #{$updated_prescription_after_decrement->id}. Skipping SKU swap."
                            );
                        } else {
                            $total_doses_in_schedule = count($dose_schedule);
                            // Ex: 2 refills, 3 items id dose_schedule
                            // on first recurring order: -> refills remaining = 1
                            // so next_dose_index = 2, which will be the final dose
                            $next_dose_index =
                                $total_doses_in_schedule -
                                $current_refills_remaining;

                            if (
                                $next_dose_index >= 0 &&
                                $next_dose_index < $total_doses_in_schedule
                            ) {
                                $next_dose_info =
                                    $dose_schedule[$next_dose_index] ?? null;

                                if (
                                    $next_dose_info &&
                                    isset(
                                        $next_dose_info["shopify_variant_gid"]
                                    )
                                ) {
                                    // Extract the numeric part from the variant GID
                                    $new_variant_gid_full =
                                        $next_dose_info["shopify_variant_gid"];
                                    $new_variant_gid_numeric = preg_replace(
                                        "/[^0-9]/",
                                        "",
                                        $new_variant_gid_full
                                    );
                                    Log::info(
                                        "Attempting SKU swap for subscription {$localSubscription->recharge_subscription_id} to variant GID {$new_variant_gid_numeric} (next dose index: {$next_dose_index})"
                                    );

                                    // DISPATCH SkuSwapJob
                                    SkuSwapJob::dispatch(
                                        $localSubscription->recharge_subscription_id,
                                        $new_variant_gid_numeric,
                                        $updated_prescription_after_decrement->id
                                    );
                                } else {
                                    Log::info(
                                        "Next dose info or GID not found for SKU swap. Prescription #{$updated_prescription_after_decrement->id}, next_dose_index: {$next_dose_index}."
                                    );
                                }
                            } else {
                                Log::info(
                                    "No more doses in schedule for SKU swap or invalid index. Prescription #{$updated_prescription_after_decrement->id}. next_dose_index: {$next_dose_index}"
                                );
                                // After processing this refill, if the next_dose_index is invalid -> cancel subscription
                                CancelSubscriptionJob::dispatch(
                                    $localSubscription->recharge_subscription_id,
                                    $updated_prescription_after_decrement->id
                                );
                            }
                        }
                        return response()->json(
                            [
                                "message" =>
                                    "Renewal order processed, attachment job dispatched",
                            ],
                            200
                        );
                    }
                } else {
                    Log::error("Subscription has no associated prescription", [
                        "subscription_id" => $rechargeSubId,
                        "order_id" => $currentShopifyOrderId,
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
                    "order_id" => $currentShopifyOrderId,
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
                        "shopify_order_id" => $currentShopifyOrderId,
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
                        "original_shopify_order_id" => $currentShopifyOrderId,
                        "product_name" => $firstItem["product_title"],
                        "status" => "active",
                        "user_id" => $user->id,
                        "prescription_id" => $prescriptionIdFromAttribute,
                    ]
                );

                // Handle prescription attachment to the order
                $prescription = Prescription::find(
                    $prescriptionIdFromAttribute
                );
                if ($prescription) {
                    if ($prescription->status === "pending_payment") {
                        $prescription->status = "pending_signature";
                        $prescription->save();
                    }

                    // JOB 1: Generate and attach prescription label to Shopify order
                    AttachLabelToShopifyJob::dispatch(
                        $prescription->id,
                        $currentShopifyOrderId
                    );

                    // If prescription is already signed, dispatch job to attach signed PDF
                    if (
                        !empty($prescription->signed_prescription_supabase_path)
                    ) {
                        // JOB 2: Process the signed prescription
                        ProcessSignedPrescriptionJob::dispatch(
                            $prescription->id,
                            $currentShopifyOrderId
                        );
                        Log::info(
                            "Dispatched ProcessSignedPrescriptionJob for CHECKOUT order prescription #{$prescription->id} and order {$currentShopifyOrderId}"
                        );

                        // Update the status to 'active'
                        $prescription->status = "active";
                        $prescription->save();
                    } else {
                        Log::info(
                            "Prescription #{$prescription->id} does not have a signed document available yet. Will be attached when signature is completed."
                        );
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

                // Commit the transaction
                DB::commit();

                // SKU Swap for the NEXT CHARGE after CHECKOUT
                if ($prescription) {
                    $dose_schedule = $prescription->dose_schedule;
                    if (is_array($dose_schedule) && count($dose_schedule) > 1) {
                        // The next dose is at index 1
                        $next_dose_info = $dose_schedule[1] ?? null;

                        // Extract the numeric part from the variant GID
                        $new_variant_gid_full =
                            $next_dose_info["shopify_variant_gid"];
                        $new_variant_gid_numeric = preg_replace(
                            "/[^0-9]/",
                            "",
                            $new_variant_gid_full
                        );

                        if ($new_variant_gid_numeric) {
                            Log::info(
                                "Attempting SKU swap after CHECKOUT for subscription {$subscription->recharge_subscription_id} to variant GID {$new_variant_gid_numeric} (dose_schedule index 1)"
                            );
                            $rechargeServiceInstance = app(
                                RechargeService::class
                            );
                            $swap_success = $rechargeServiceInstance->updateSubscriptionVariant(
                                $subscription->recharge_subscription_id,
                                $new_variant_gid_numeric
                            );
                            if ($swap_success) {
                                Log::info(
                                    "Successfully swapped SKU after CHECKOUT for subscription {$subscription->recharge_subscription_id}"
                                );
                            } else {
                                Log::error(
                                    "Failed to swap SKU after CHECKOUT for subscription {$subscription->recharge_subscription_id}"
                                );
                            }
                        }
                    }
                }

                // Return a success response
                return response()->json(
                    ["message" => "Checkout order processed successfully"],
                    200
                );
            } catch (\Exception $e) {
                // Rollback the transaction in case of error
                DB::rollback();

                Log::error("Error processing Recharge order", [
                    "order_id" => $currentShopifyOrderId,
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
                "order_id" => $currentShopifyOrderId,
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

    /**
     * Handle an incoming subscription updated webhook from Recharge.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function subscriptionUpdated(Request $request)
    {
        Log::info("Recharge subscription updated webhook received.");

        // Validate the webhook
        // if (!$this->validateWebhook($request)) {
        //     Log::warning(
        //         "Recharge subscription updated webhook validation failed."
        //     );
        //     return response()->json(
        //         ["status" => "error", "message" => "Webhook validation failed"],
        //         401
        //     );
        // }

        $payload = $request->json()->all();
        $rechargeSubscriptionData = $payload["subscription"] ?? null;

        if (!$rechargeSubscriptionData) {
            Log::error(
                "Recharge subscription updated webhook: Missing subscription data in payload.",
                $payload
            );
            return response()->json(
                ["status" => "error", "message" => "Missing subscription data"],
                400
            );
        }

        $rechargeSubscriptionId = $rechargeSubscriptionData["id"] ?? null;
        if (!$rechargeSubscriptionId) {
            Log::error(
                "Recharge subscription updated webhook: Missing subscription ID in payload.",
                $payload
            );
            return response()->json(
                ["status" => "error", "message" => "Missing subscription ID"],
                400
            );
        }

        try {
            $subscription = Subscription::where(
                "recharge_subscription_id",
                $rechargeSubscriptionId
            )->first();

            if (!$subscription) {
                Log::warning(
                    "Recharge subscription updated webhook: Subscription not found in local database.",
                    [
                        "recharge_subscription_id" => $rechargeSubscriptionId,
                    ]
                );
                return response()->json(
                    [
                        "status" => "error",
                        "message" => "Subscription not found",
                    ],
                    404
                );
            }

            // Fields to update
            $updateData = [];
            if (isset($rechargeSubscriptionData["status"])) {
                $updateData["status"] = strtolower(
                    $rechargeSubscriptionData["status"]
                );
            }
            if (isset($rechargeSubscriptionData["next_charge_scheduled_at"])) {
                // Ensure the date format is compatible with your model's cast
                $updateData["next_charge_scheduled_at"] =
                    $rechargeSubscriptionData["next_charge_scheduled_at"];
            }
            if (isset($rechargeSubscriptionData["shopify_product_id"])) {
                $updateData["shopify_product_id"] =
                    $rechargeSubscriptionData["shopify_product_id"];
            }

            if (!empty($updateData)) {
                $subscription->fill($updateData);
                $subscription->save();
                Log::info(
                    "Subscription updated successfully from Recharge webhook.",
                    [
                        "local_subscription_id" => $subscription->id,
                        "recharge_subscription_id" => $rechargeSubscriptionId,
                        "updated_fields" => array_keys($updateData),
                    ]
                );
            } else {
                Log::info(
                    "Recharge subscription updated webhook: No relevant data to update.",
                    [
                        "recharge_subscription_id" => $rechargeSubscriptionId,
                    ]
                );
            }

            return response()->json([
                "status" => "success",
                "message" => "Webhook processed successfully",
            ]);
        } catch (\Exception $e) {
            Log::error(
                "Error processing Recharge subscription updated webhook.",
                [
                    "recharge_subscription_id" => $rechargeSubscriptionId,
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            return response()->json(
                ["status" => "error", "message" => "Internal server error"],
                500
            );
        }
    }
}
