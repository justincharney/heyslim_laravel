<?php

namespace App\Http\Controllers;

use App\Jobs\CreateInitialShopifyOrderJob;

use App\Jobs\UpdateSubscriptionDoseJob;
use App\Models\Prescription;
use App\Models\ProcessedRecurringOrder;
use App\Models\QuestionnaireSubmission;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\QuestionnaireSubmittedNotification;
use App\Services\ChargebeeService;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Newsletter\Facades\Newsletter;

class ChargebeeWebhookController extends Controller
{
    protected ChargebeeService $chargebeeService;
    protected ShopifyService $shopifyService;

    public function __construct(
        ChargebeeService $chargebeeService,
        ShopifyService $shopifyService,
    ) {
        $this->chargebeeService = $chargebeeService;
        $this->shopifyService = $shopifyService;
    }

    /**
     * Handle subscription cancelled webhook
     */
    public function subscriptionCancelled(Request $request)
    {
        $data = $request->json()->all();
        $subscriptionData = $data["content"]["subscription"] ?? null;

        if (!$subscriptionData) {
            Log::error(
                "Chargebee subscription cancelled webhook missing data",
                $data,
            );
            return response()->json(
                ["error" => "Missing subscription data"],
                400,
            );
        }

        try {
            DB::beginTransaction();

            $localSubscription = Subscription::where(
                "chargebee_subscription_id",
                $subscriptionData["id"],
            )->first();

            if ($localSubscription) {
                $localSubscription->update(["status" => "cancelled"]);

                if ($localSubscription->prescription_id) {
                    $prescription = Prescription::with("clinicalPlan")->find(
                        $localSubscription->prescription_id,
                    );
                    if ($prescription) {
                        $prescription->update(["status" => "cancelled"]);

                        // Also mark the associated clinical plan as completed
                        if ($prescription->clinicalPlan) {
                            $prescription->clinicalPlan->update([
                                "status" => "completed",
                            ]);
                        }
                    }
                }

                DB::commit();

                return response()->json([
                    "message" => "Subscription cancelled successfully",
                ]);
            } else {
                DB::rollBack();
                Log::warning("Subscription not found for cancellation", [
                    "chargebee_id" => $subscriptionData["id"],
                ]);
                return response()->json(
                    ["error" => "Subscription not found"],
                    404,
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing Chargebee subscription cancellation", [
                "subscription_id" => $subscriptionData["id"],
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing cancellation"],
                500,
            );
        }
    }

    /**
     * Handle subscription changed webhook (plan changes, etc.)
     */
    public function subscriptionChanged(Request $request)
    {
        $data = $request->json()->all();
        $subscriptionData = $data["content"]["subscription"] ?? null;

        if (!$subscriptionData) {
            Log::error(
                "Chargebee subscription changed webhook missing data",
                $data,
            );
            return response()->json(
                ["error" => "Missing subscription data"],
                400,
            );
        }

        try {
            $localSubscription = Subscription::where(
                "chargebee_subscription_id",
                $subscriptionData["id"],
            )->first();

            if ($localSubscription) {
                $localSubscription->update([
                    "chargebee_item_price_id" =>
                        $subscriptionData["subscription_items"][0][
                            "item_price_id"
                        ] ?? $localSubscription->chargebee_item_price_id,
                    "status" => strtolower($subscriptionData["status"]),
                    "next_charge_scheduled_at" => isset(
                        $subscriptionData["next_billing_at"],
                    )
                        ? date(
                            "Y-m-d H:i:s",
                            $subscriptionData["next_billing_at"],
                        )
                        : null,
                ]);
            }

            return response()->json([
                "message" => "Subscription updated successfully",
            ]);
        } catch (\Exception $e) {
            Log::error("Error processing Chargebee subscription change", [
                "subscription_id" => $subscriptionData["id"],
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing subscription change"],
                500,
            );
        }
    }

    /**
     * Handle payment succeeded webhook
     */
    public function paymentSucceeded(Request $request)
    {
        $data = $request->json()->all();
        $subscriptionData = $data["content"]["subscription"] ?? null;
        $customerData = $data["content"]["customer"] ?? null;
        $invoiceData = $data["content"]["invoice"] ?? null;

        if (!$invoiceData || !isset($invoiceData["id"])) {
            Log::error(
                "Chargebee payment succeeded webhook missing invoice data",
                $data,
            );
            return response()->json(["error" => "Missing invoice data"], 400);
        }

        $invoiceId = $invoiceData["id"];

        try {
            DB::beginTransaction();

            // Check if this is a one-time charge (consultation payment)
            $isOneTimeCharge =
                !$subscriptionData ||
                (isset($invoiceData["recurring"]) &&
                    $invoiceData["recurring"] === false);

            if ($isOneTimeCharge) {
                $this->handleOneTimeCharge($customerData, $invoiceData);
                DB::commit();
                return response()->json([
                    "message" => "One-time payment processed successfully",
                ]);
            }

            // Use updateOrCreate to handle subscription creation and updates atomically.
            if ($subscriptionData && $customerData) {
                $user = User::where("email", $customerData["email"])->first();
                if (!$user) {
                    throw new \Exception(
                        "User not found with email: " . $customerData["email"],
                    );
                }

                // Validate and extract required data from the webhook
                $chargebeeCustomerId = $customerData["id"] ?? null;
                $status = isset($subscriptionData["status"])
                    ? strtolower($subscriptionData["status"])
                    : null;
                $nextChargeAt = isset($subscriptionData["next_billing_at"])
                    ? date("Y-m-d H:i:s", $subscriptionData["next_billing_at"])
                    : null;
                $questionnaireSubmissionId =
                    $subscriptionData["cf_questionnaire_submission_id"] ?? null;
                $chargebeeItemPriceId =
                    $subscriptionData["subscription_items"][0][
                        "item_price_id"
                    ] ?? null;

                if (
                    !$chargebeeCustomerId ||
                    !$status ||
                    !$nextChargeAt ||
                    !$questionnaireSubmissionId ||
                    !$chargebeeItemPriceId
                ) {
                    throw new \Exception(
                        "Webhook is missing one or more required fields.",
                    );
                }

                // Prepare data for creating or updating the subscription
                $subscriptionValues = [
                    "chargebee_customer_id" => $chargebeeCustomerId,
                    "user_id" => $user->id,
                    "status" => $status,
                    "next_charge_scheduled_at" => $nextChargeAt,
                    "questionnaire_submission_id" => $questionnaireSubmissionId,
                    "chargebee_item_price_id" => $chargebeeItemPriceId,
                ];

                $localSubscription = Subscription::updateOrCreate(
                    [
                        "chargebee_subscription_id" => $subscriptionData["id"],
                    ],
                    $subscriptionValues,
                );
            } else {
                // If we don't have subscription data, we can't proceed.
                throw new \Exception(
                    "Subscription data is missing from the webhook.",
                );
            }

            // Check if this is a reactivation (subscription was previously cancelled/inactive)
            $isReactivation = $this->isSubscriptionReactivation(
                $localSubscription,
                $subscriptionData,
            );

            if ($isReactivation) {
                $this->handleSubscriptionReactivation(
                    $localSubscription,
                    $subscriptionData,
                );
            } else {
                $isInitialPayment = empty(
                    $localSubscription->latest_shopify_order_id
                );

                // Log::info("Processing payment", [
                //     "payment_type" => $isInitialPayment ? "initial" : "recurring",
                //     "subscription_id" => $localSubscription->id,
                // ]);

                if ($isInitialPayment) {
                    $this->handleInitialPayment($localSubscription);
                } else {
                    $this->handleRecurringPayment(
                        $localSubscription,
                        $invoiceId,
                    );
                }
            }

            DB::commit();

            return response()->json([
                "message" => "Payment processed successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing Chargebee payment", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return response()->json(
                ["error" => "Error processing payment: " . $e->getMessage()],
                500,
            );
        }
    }

    /**
     * Handle initial payment (first payment for subscription)
     */
    private function handleInitialPayment(Subscription $subscription): void
    {
        // Handle questionnaire submission completion
        if ($subscription->questionnaire_submission_id) {
            $questionnaireSubmission = QuestionnaireSubmission::find(
                $subscription->questionnaire_submission_id,
            );

            if (
                $questionnaireSubmission &&
                $questionnaireSubmission->status === "pending_payment"
            ) {
                $questionnaireSubmission->status = "submitted";
                $questionnaireSubmission->save();

                // Send notification to user
                $user = User::find($subscription->user_id);
                if ($user) {
                    $user->notify(
                        new QuestionnaireSubmittedNotification(
                            $questionnaireSubmission,
                        ),
                    );

                    // Remove from inactive-user drip campaign
                    if (Newsletter::isSubscribed($user->email)) {
                        $api = Newsletter::getApi();
                        $listId = config(
                            "newsletter.lists." .
                                config("newsletter.default_list_name") .
                                ".id",
                        );
                        $subscriberHash = md5(strtolower($user->email));

                        $api->post(
                            "lists/{$listId}/members/{$subscriberHash}/tags",
                            [
                                "tags" => [
                                    [
                                        "name" => "inactive-user",
                                        "status" => "inactive",
                                    ],
                                ],
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * Handle recurring payment (renewal payment)
     */
    private function handleRecurringPayment(
        Subscription $subscription,
        string $invoiceId,
    ): void {
        $prescription = $subscription->prescription;

        if (!$prescription || $prescription->status !== "active") {
            Log::error("No active prescription found for recurring payment", [
                "subscription_id" => $subscription->id,
                "prescription_id" => $prescription?->id,
                "prescription_status" => $prescription?->status,
            ]);
            throw new \Exception(
                "No active prescription found for recurring payment. Subscription ID: {$subscription->id}",
            );
        }

        // For replacement prescriptions, we need to check if this is their first recurring order
        // to avoid race conditions, we'll use a database transaction
        $shouldDecrementRefills = true;
        $isReplacement = $prescription->replaces_prescription_id !== null;

        if ($isReplacement) {
            // Use a transaction to prevent race conditions when checking for existing orders
            DB::transaction(function () use (
                $prescription,
                &$shouldDecrementRefills,
            ) {
                $hasRecurring = ProcessedRecurringOrder::where(
                    "prescription_id",
                    $prescription->id,
                )
                    ->lockForUpdate()
                    ->exists();

                if (!$hasRecurring) {
                    // This is the first recurring order for a replacement prescription
                    // Treat it as an "initial dose" - don't decrement refills
                    $shouldDecrementRefills = false;
                    Log::info(
                        "This is the first order ever for replacement prescription #{$prescription->id}. " .
                            "Treating this RECURRING order as initial dose - not decrementing refills.",
                    );
                }
            });
        }

        // Use firstOrCreate to prevent race condition where multiple webhooks
        // could create duplicate ProcessedRecurringOrder records for the same prescription
        $processedOrder = ProcessedRecurringOrder::firstOrCreate(
            [
                "prescription_id" => $prescription->id,
                "chargebee_invoice_id" => $invoiceId,
            ],
            [
                "chargebee_invoice_id" => $invoiceId,
            ],
        );

        // Only dispatch the job if we actually created a new record (not if it already existed)
        if ($processedOrder->wasRecentlyCreated) {
            // Decrement refills BEFORE creating the order so the order uses the correct dose
            // Note: Initial orders never decrement refills because
            // the refills count represents the number of ADDITIONAL refills beyond the initial prescription
            if ($shouldDecrementRefills && $prescription->refills > 0) {
                $prescription->decrement("refills");
            }

            CreateInitialShopifyOrderJob::dispatch(
                $prescription->id,
                $invoiceId,
            );

            // Log::info(
            //     "Dispatched CreateRenewalShopifyOrderJob for new recurring order",
            //     [
            //         "prescription_id" => $prescription->id,
            //         "processed_order_id" => $processedOrder->id,
            //     ],
            // );
        } else {
            Log::info(
                "ProcessedRecurringOrder already exists, skipping job dispatch",
                [
                    "prescription_id" => $prescription->id,
                    "existing_order_id" => $processedOrder->id,
                ],
            );
        }
    }

    /**
     * Check if this payment represents a subscription reactivation
     */
    private function isSubscriptionReactivation(
        Subscription $localSubscription,
        array $subscriptionData,
    ): bool {
        // If the subscription is currently cancelled/inactive locally but active in Chargebee,
        $subscriptionIsActive =
            strtolower($subscriptionData["status"]) === "active";
        $localSubscriptionWasInactive = in_array($localSubscription->status, [
            "cancelled",
            "paused",
        ]);

        return $subscriptionIsActive && $localSubscriptionWasInactive;
    }

    /**
     * Handle subscription reactivation
     */
    private function handleSubscriptionReactivation(
        Subscription $localSubscription,
        array $subscriptionData,
    ): void {
        // Update subscription status and billing information
        $localSubscription->update([
            "status" => strtolower($subscriptionData["status"]),
            "next_charge_scheduled_at" => isset(
                $subscriptionData["next_billing_at"],
            )
                ? date("Y-m-d H:i:s", $subscriptionData["next_billing_at"])
                : null,
        ]);

        // If there's an associated prescription, reactivate it as well
        if ($localSubscription->prescription_id) {
            $prescription = Prescription::find(
                $localSubscription->prescription_id,
            );
            if ($prescription && $prescription->status === "cancelled") {
                $prescription->update(["status" => "active"]);

                Log::info(
                    "Reactivated prescription during subscription reactivation",
                    [
                        "subscription_id" => $localSubscription->id,
                        "prescription_id" => $prescription->id,
                    ],
                );
            }
        }

        Log::info("Subscription reactivated successfully", [
            "subscription_id" => $localSubscription->id,
            "has_prescription" => $localSubscription->prescription_id !== null,
        ]);
    }

    /**
     * Handle one-time charge payments (like consultations)
     */
    private function handleOneTimeCharge(
        ?array $customerData,
        array $invoiceData,
    ): void {
        if (!$customerData || !isset($customerData["email"])) {
            Log::error("One-time charge missing customer data", [
                "invoice_id" => $invoiceData["id"] ?? null,
            ]);
            throw new \Exception("Missing customer data for one-time charge");
        }

        $user = User::where("email", $customerData["email"])->first();
        if (!$user) {
            Log::error("User not found for one-time charge", [
                "email" => $customerData["email"],
                "invoice_id" => $invoiceData["id"] ?? null,
            ]);
            throw new \Exception("User not found for one-time charge");
        }

        // Generate Calendly link and send consultation notification
        $calendlyService = app(\App\Services\CalendlyService::class);
        try {
            $calendlyResult = $calendlyService->selectProviderAndGenerateLink(
                $user,
            );

            if ($calendlyResult) {
                $user->notify(
                    new \App\Notifications\ScheduleConsultationNotification(
                        null, // no questionnaire submission for direct consultation purchase
                        $calendlyResult["provider"],
                        $calendlyResult["booking_url"],
                    ),
                );

                Log::info(
                    "One-time consultation payment processed successfully",
                    [
                        "user_id" => $user->id,
                        "invoice_id" => $invoiceData["id"] ?? null,
                        "provider" => $calendlyResult["provider"] ?? null,
                    ],
                );
            } else {
                Log::error(
                    "Failed to generate Calendly link for one-time charge",
                    [
                        "user_id" => $user->id,
                        "invoice_id" => $invoiceData["id"] ?? null,
                    ],
                );
                throw new \Exception("Failed to generate consultation link");
            }
        } catch (\Exception $e) {
            Log::error("Exception processing one-time consultation charge", [
                "user_id" => $user->id,
                "invoice_id" => $invoiceData["id"] ?? null,
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
