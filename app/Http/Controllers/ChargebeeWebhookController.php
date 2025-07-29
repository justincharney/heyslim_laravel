<?php

namespace App\Http\Controllers;

use App\Jobs\AttachLabelToShopifyJob;
use App\Jobs\CancelSubscriptionJob;
use App\Jobs\ProcessSignedPrescriptionJob;
use App\Notifications\QuestionnaireSubmittedNotification;
use App\Services\ChargebeeService;
use App\Services\ShopifyService;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Prescription;
use App\Models\ProcessedRecurringOrder;
use App\Models\QuestionnaireSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
     * Handle subscription created webhook
     */
    public function subscriptionCreated(Request $request)
    {
        $data = $request->json()->all();
        $subscription = $data["content"]["subscription"] ?? null;
        $customer = $data["content"]["customer"] ?? null;

        if (!$subscription || !$customer) {
            Log::error(
                "Chargebee subscription created webhook missing data",
                $data,
            );
            return response()->json(
                ["error" => "Missing subscription or customer data"],
                400,
            );
        }

        try {
            DB::beginTransaction();

            // Find user by customer email or custom field user ID
            $user = User::where("email", $customer["email"])->first();

            if (!$user) {
                Log::error("User not found for Chargebee subscription", [
                    "email" => $customer["email"],
                    "subscription_id" => $subscription["id"],
                ]);
                DB::rollBack();
                return response()->json(["error" => "User not found"], 404);
            }

            // Get questionnaire submission ID from custom fields if available
            $questionnaireSubmissionId =
                $subscription["cf_questionnaire_submission_id"] ?? null;

            if (!$questionnaireSubmissionId) {
                // Log detailed information about available custom fields
                $availableCustomFields = array_filter(
                    $subscription,
                    function ($key) {
                        return str_starts_with($key, "cf_");
                    },
                    ARRAY_FILTER_USE_KEY,
                );

                Log::error(
                    "Questionnaire submission ID not found for Chargebee subscription",
                    [
                        "email" => $customer["email"],
                        "subscription_id" => $subscription["id"],
                        "complete_webhook_data" => $data,
                    ],
                );
                DB::rollBack();
                return response()->json(
                    ["error" => "Questionnaire submission ID not found"],
                    404,
                );
            }

            // Extract product information (PC 2.0 uses subscription_items)
            $productName = $this->extractProductName($subscription);

            // Create or update local subscription record
            Subscription::updateOrCreate(
                ["chargebee_subscription_id" => $subscription["id"]],
                [
                    "chargebee_customer_id" => $customer["id"],
                    "user_id" => $user->id,
                    "questionnaire_submission_id" => $questionnaireSubmissionId,
                    "product_name" => $productName,
                    "status" => strtolower($subscription["status"]),
                    "next_charge_scheduled_at" => isset(
                        $subscription["next_billing_at"],
                    )
                        ? date("Y-m-d H:i:s", $subscription["next_billing_at"])
                        : null,
                ],
            );

            // Update questionnaire submission status
            $questionnaireSubmission = QuestionnaireSubmission::find(
                $questionnaireSubmissionId,
            );
            if (
                $questionnaireSubmission &&
                $questionnaireSubmission->status === "pending_payment"
            ) {
                $questionnaireSubmission->status = "submitted";
                $questionnaireSubmission->save();

                // Send the patient (user) the notification
                $user->notify(
                    new QuestionnaireSubmittedNotification(
                        $questionnaireSubmission,
                    ),
                );

                // If they have submitted the questionnaire, remove them from the inactive-user drip campaign
                if (Newsletter::isSubscribed($user->email)) {
                    // Remove specific tag(s) using the raw Mailchimp API
                    $api = Newsletter::getApi();
                    $listId = config(
                        "newsletter.lists." .
                            config("newsletter.default_list_name") .
                            ".id",
                    ); // Get your Mailchimp list ID from config
                    $subscriberHash = md5(strtolower($user->email)); // Mailchimp requires the subscriber hash (lowercase MD5 of email)

                    $api->post(
                        "lists/{$listId}/members/{$subscriberHash}/tags",
                        [
                            "tags" => [
                                [
                                    "name" => "inactive-user",
                                    "status" => "inactive", // This removes the tag
                                ],
                            ],
                        ],
                    );
                }
            } else {
                Log::error(
                    "Questionnaire submission not found or status not pending_payment",
                );
                return response()->json(
                    [
                        "message" =>
                            "Questionnaire submission not found or status not pending_payment",
                    ],
                    400,
                );
            }

            DB::commit();

            // Log::info("Chargebee subscription created successfully", [
            //     "subscription_id" => $subscription["id"],
            //     "user_id" => $user->id,
            // ]);

            return response()->json([
                "message" => "Subscription created successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing Chargebee subscription created", [
                "subscription_id" => $subscription["id"] ?? "unknown",
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing subscription"],
                500,
            );
        }
    }

    /**
     * Extract product name from subscription for PC 2.0
     */
    private function extractProductName(array $subscription): string
    {
        // PC 2.0: Check for subscription_items
        if (
            isset($subscription["subscription_items"]) &&
            !empty($subscription["subscription_items"])
        ) {
            $firstItem = $subscription["subscription_items"][0];
            return $firstItem["item_price_id"] ?? "Unknown Product";
        }
    }

    /**
     * Handle subscription cancelled webhook
     */
    public function subscriptionCancelled(Request $request)
    {
        $data = $request->json()->all();
        $subscription = $data["content"]["subscription"] ?? null;

        if (!$subscription) {
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
                $subscription["id"],
            )->first();

            if ($localSubscription) {
                // Update subscription status
                $localSubscription->update(["status" => "cancelled"]);

                // Update linked prescription if exists
                if ($localSubscription->prescription_id) {
                    Prescription::where(
                        "id",
                        $localSubscription->prescription_id,
                    )->update(["status" => "cancelled"]);
                }

                DB::commit();

                Log::info("Chargebee subscription cancelled successfully", [
                    "subscription_id" => $subscription["id"],
                ]);

                return response()->json([
                    "message" => "Subscription cancelled successfully",
                ]);
            } else {
                Log::warning("Subscription not found for cancellation", [
                    "chargebee_id" => $subscription["id"],
                ]);
                return response()->json(
                    ["error" => "Subscription not found"],
                    404,
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing Chargebee subscription cancellation", [
                "subscription_id" => $subscription["id"],
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
        $subscription = $data["content"]["subscription"] ?? null;

        if (!$subscription) {
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
                $subscription["id"],
            )->first();

            if ($localSubscription) {
                $localSubscription->update([
                    "product_name" => $subscription["plan_id"],
                    "status" => strtolower($subscription["status"]),
                    "next_charge_scheduled_at" => isset(
                        $subscription["next_billing_at"],
                    )
                        ? date("Y-m-d H:i:s", $subscription["next_billing_at"])
                        : null,
                ]);

                Log::info("Chargebee subscription updated successfully", [
                    "subscription_id" => $subscription["id"],
                ]);
            }

            return response()->json([
                "message" => "Subscription updated successfully",
            ]);
        } catch (\Exception $e) {
            Log::error("Error processing Chargebee subscription change", [
                "subscription_id" => $subscription["id"],
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing subscription change"],
                500,
            );
        }
    }

    /**
     * Handle invoice generated webhook (equivalent to recurring order)
     */
    public function invoiceGenerated(Request $request)
    {
        $data = $request->json()->all();
        $invoice = $data["content"]["invoice"] ?? null;
        $subscription = $data["content"]["subscription"] ?? null;

        if (!$invoice || !$subscription) {
            Log::error(
                "Chargebee invoice generated webhook missing data",
                $data,
            );
            return response()->json(
                ["error" => "Missing invoice or subscription data"],
                400,
            );
        }

        // Skip one-time invoices or non-recurring charges
        if (
            $subscription["status"] !== "active" ||
            empty($invoice["recurring"])
        ) {
            return response()->json(
                ["message" => "Non-recurring invoice ignored"],
                200,
            );
        }

        try {
            $localSubscription = Subscription::where(
                "chargebee_subscription_id",
                $subscription["id"],
            )->first();

            if (!$localSubscription || !$localSubscription->prescription_id) {
                Log::warning("No prescription found for Chargebee invoice", [
                    "subscription_id" => $subscription["id"],
                    "invoice_id" => $invoice["id"],
                ]);
                return response()->json(
                    ["message" => "No prescription found"],
                    200,
                );
            }

            $prescription = $localSubscription->prescription;

            // Check if we've already processed this invoice
            $alreadyProcessed = ProcessedRecurringOrder::where(
                "chargebee_invoice_id",
                $invoice["id"],
            )
                ->where("prescription_id", $prescription->id)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("Chargebee invoice already processed", [
                    "invoice_id" => $invoice["id"],
                    "prescription_id" => $prescription->id,
                ]);
                return response()->json(
                    ["message" => "Invoice already processed"],
                    200,
                );
            }

            // Handle refill decrement logic (similar to RechargeWebhookController)
            $isReplacement = !is_null($prescription->replaces_prescription_id);
            $shouldDecrementRefills = true;

            if ($isReplacement) {
                $prescriptionEverProcessed = ProcessedRecurringOrder::where(
                    "prescription_id",
                    $prescription->id,
                )->exists();
                $hasHadInitialOrder =
                    $prescription->subscription &&
                    $prescription->subscription->original_shopify_order_id;

                if (!$prescriptionEverProcessed && !$hasHadInitialOrder) {
                    $shouldDecrementRefills = false;
                    Log::info(
                        "First invoice for replacement prescription - not decrementing refills",
                        [
                            "prescription_id" => $prescription->id,
                        ],
                    );
                }
            }

            DB::beginTransaction();

            // Reload prescription with lock
            $prescription = Prescription::lockForUpdate()->findOrFail(
                $prescription->id,
            );

            if ($shouldDecrementRefills && $prescription->refills > 0) {
                $prescription->refills -= 1;
                $prescription->save();
            }

            // Record that this invoice was processed
            ProcessedRecurringOrder::create([
                "chargebee_invoice_id" => $invoice["id"],
                "prescription_id" => $prescription->id,
                "processed_at" => now(),
            ]);

            DB::commit();

            // Handle plan changes for next renewal based on dose schedule
            $this->handlePlanChangeForNextRenewal(
                $prescription,
                $localSubscription,
            );

            Log::info("Chargebee invoice processed successfully", [
                "invoice_id" => $invoice["id"],
                "prescription_id" => $prescription->id,
                "refills_remaining" => $prescription->refills,
            ]);

            return response()->json([
                "message" => "Invoice processed successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing Chargebee invoice", [
                "invoice_id" => $invoice["id"],
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing invoice"],
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
        $transaction = $data["content"]["transaction"] ?? null;
        $invoice = $data["content"]["invoice"] ?? null;
        $subscription = $data["content"]["subscription"] ?? null;

        if (!$transaction || !$invoice) {
            Log::error(
                "Chargebee payment succeeded webhook missing data",
                $data,
            );
            return response()->json(
                ["error" => "Missing transaction or invoice data"],
                400,
            );
        }

        try {
            $localSubscription = null;

            if ($subscription) {
                $localSubscription = Subscription::where(
                    "chargebee_subscription_id",
                    $subscription["id"],
                )->first();
            }

            // Handle questionnaire submission completion
            if (
                $localSubscription &&
                $localSubscription->questionnaire_submission_id
            ) {
                $this->handleQuestionnairePaymentSuccess($localSubscription);
            }

            // Create Shopify order for the payment
            $shopifyOrderId = $this->createShopifyOrderFromPayment(
                $transaction,
                $invoice,
                $localSubscription,
            );

            if (
                $shopifyOrderId &&
                $localSubscription &&
                $localSubscription->prescription_id
            ) {
                // Dispatch label attachment job
                AttachLabelToShopifyJob::dispatch(
                    $localSubscription->prescription_id,
                    $shopifyOrderId,
                );

                // If prescription is signed, attach the document
                $prescription = $localSubscription->prescription;
                if (
                    $prescription &&
                    !empty($prescription->signed_prescription_supabase_path)
                ) {
                    ProcessSignedPrescriptionJob::dispatch(
                        $prescription->id,
                        $shopifyOrderId,
                    );
                }
            }

            Log::info("Chargebee payment processed successfully", [
                "transaction_id" => $transaction["id"],
                "shopify_order_id" => $shopifyOrderId,
            ]);

            return response()->json([
                "message" => "Payment processed successfully",
            ]);
        } catch (\Exception $e) {
            Log::error("Error processing Chargebee payment", [
                "transaction_id" => $transaction["id"],
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing payment"],
                500,
            );
        }
    }

    /**
     * Handle questionnaire payment success
     */
    private function handleQuestionnairePaymentSuccess(
        Subscription $subscription,
    ): void {
        try {
            $questionnaireSubmission = $subscription->questionnaireSubmission;

            if (!$questionnaireSubmission) {
                Log::warning(
                    "Questionnaire submission not found for subscription",
                    [
                        "subscription_id" => $subscription->id,
                        "questionnaire_submission_id" =>
                            $subscription->questionnaire_submission_id,
                    ],
                );
                return;
            }

            // Update questionnaire submission status
            if ($questionnaireSubmission->status === "pending_payment") {
                $questionnaireSubmission->status = "completed";
                $questionnaireSubmission->save();

                Log::info("Questionnaire submission marked as completed", [
                    "questionnaire_submission_id" =>
                        $questionnaireSubmission->id,
                    "subscription_id" => $subscription->id,
                ]);

                // TODO: Add any post-payment questionnaire logic here
                // e.g., send confirmation email, trigger consultation scheduling, etc.
            }
        } catch (\Exception $e) {
            Log::error("Error handling questionnaire payment success", [
                "subscription_id" => $subscription->id,
                "error" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle hosted page events (checkout completion)
     */
    public function hostedPageEvent(Request $request)
    {
        $data = $request->json()->all();
        $hostedPage = $data["content"]["hosted_page"] ?? null;

        if (!$hostedPage || $hostedPage["state"] !== "succeeded") {
            return response()->json(
                ["message" => "Hosted page not succeeded"],
                200,
            );
        }

        try {
            // Get the hosted page details from Chargebee
            $pageDetails = $this->chargebeeService->retrieveHostedPage(
                $hostedPage["id"],
            );

            if (!$pageDetails) {
                Log::error("Failed to retrieve hosted page details", [
                    "hosted_page_id" => $hostedPage["id"],
                ]);
                return response()->json(
                    ["error" => "Failed to retrieve page details"],
                    500,
                );
            }

            // Extract subscription and customer information
            $subscription = $pageDetails["content"]["subscription"] ?? null;
            $customer = $pageDetails["content"]["customer"] ?? null;

            if ($subscription && $customer) {
                // Find user and handle subscription creation
                $user = User::where("email", $customer["email"])->first();
                if ($user) {
                    $this->handleSubscriptionFromHostedPage(
                        $subscription,
                        $customer,
                        $user,
                    );
                }
            }

            return response()->json([
                "message" => "Hosted page event processed",
            ]);
        } catch (\Exception $e) {
            Log::error("Error processing Chargebee hosted page event", [
                "hosted_page_id" => $hostedPage["id"],
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Error processing hosted page event"],
                500,
            );
        }
    }

    /**
     * Handle plan change for next renewal based on dose schedule
     */
    private function handlePlanChangeForNextRenewal(
        Prescription $prescription,
        Subscription $subscription,
    ): void {
        try {
            $doseSchedule = $prescription->dose_schedule;
            if (!is_array($doseSchedule) || empty($doseSchedule)) {
                return;
            }

            $totalDoses = count($doseSchedule);
            $remainingRefills = $prescription->refills;
            $nextDoseIndex = $totalDoses - $remainingRefills;

            if ($nextDoseIndex >= 0 && $nextDoseIndex < $totalDoses) {
                $nextDose = $doseSchedule[$nextDoseIndex];
                $newPlanId = $nextDose["chargebee_plan_id"] ?? null;

                if ($newPlanId) {
                    $this->chargebeeService->updateSubscriptionPlan(
                        $subscription->chargebee_subscription_id,
                        $newPlanId,
                    );

                    Log::info(
                        "Updated Chargebee subscription plan for next renewal",
                        [
                            "subscription_id" =>
                                $subscription->chargebee_subscription_id,
                            "new_plan_id" => $newPlanId,
                            "dose_index" => $nextDoseIndex,
                        ],
                    );
                }
            } else {
                // No more doses, cancel subscription
                $this->chargebeeService->cancelSubscription(
                    $subscription->chargebee_subscription_id,
                    "prescription_completed",
                );

                Log::info("Cancelled Chargebee subscription - no more doses", [
                    "subscription_id" =>
                        $subscription->chargebee_subscription_id,
                    "prescription_id" => $prescription->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error handling plan change for next renewal", [
                "subscription_id" => $subscription->chargebee_subscription_id,
                "prescription_id" => $prescription->id,
                "error" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create Shopify order from Chargebee payment
     */
    private function createShopifyOrderFromPayment(
        $transaction,
        $invoice,
        ?Subscription $subscription,
    ): ?string {
        // This would integrate with your Shopify service to create an order
        // Implementation depends on your specific Shopify integration requirements
        // For now, we'll return a placeholder
        return "shopify_order_" . $transaction["id"];
    }

    /**
     * Handle subscription creation from hosted page
     */
    private function handleSubscriptionFromHostedPage(
        $subscription,
        $customer,
        User $user,
    ): void {
        try {
            DB::beginTransaction();

            $prescriptionId = $subscription["cf_prescription_id"] ?? null;
            $questionnaireSubmissionId =
                $subscription["cf_questionnaire_submission_id"] ?? null;

            // Create local subscription record
            $localSubscription = Subscription::updateOrCreate(
                ["chargebee_subscription_id" => $subscription["id"]],
                [
                    "chargebee_customer_id" => $customer["id"],
                    "user_id" => $user->id,
                    "prescription_id" => $prescriptionId,
                    "questionnaire_submission_id" => $questionnaireSubmissionId,
                    "product_name" => $subscription["plan_id"],
                    "status" => strtolower($subscription["status"]),
                    "next_charge_scheduled_at" => isset(
                        $subscription["next_billing_at"],
                    )
                        ? date("Y-m-d H:i:s", $subscription["next_billing_at"])
                        : null,
                ],
            );

            // Update prescription status if exists
            if ($prescriptionId) {
                $prescription = Prescription::find($prescriptionId);
                if (
                    $prescription &&
                    $prescription->status === "pending_payment"
                ) {
                    $prescription->status = "pending_signature";
                    $prescription->save();
                }
            }

            // Update questionnaire submission status if exists
            if ($questionnaireSubmissionId) {
                $questionnaireSubmission = QuestionnaireSubmission::find(
                    $questionnaireSubmissionId,
                );
                if (
                    $questionnaireSubmission &&
                    $questionnaireSubmission->status === "pending_payment"
                ) {
                    $questionnaireSubmission->status = "submitted";
                    $questionnaireSubmission->save();
                }
            }

            DB::commit();

            Log::info("Subscription created from hosted page", [
                "subscription_id" => $subscription["id"],
                "user_id" => $user->id,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error handling subscription from hosted page", [
                "subscription_id" => $subscription["id"],
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
