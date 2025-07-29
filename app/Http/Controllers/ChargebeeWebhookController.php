<?php

namespace App\Http\Controllers;

use App\Jobs\CreateInitialShopifyOrderJob;
use App\Jobs\CreateRenewalShopifyOrderJob;
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

            $user = User::where("email", $customer["email"])->first();

            if (!$user) {
                Log::error("User not found for Chargebee subscription", [
                    "email" => $customer["email"],
                    "subscription_id" => $subscription["id"],
                ]);
                DB::rollBack();
                return response()->json(["error" => "User not found"], 404);
            }

            $questionnaireSubmissionId =
                $subscription["cf_questionnaire_submission_id"] ?? null;
            if (!$questionnaireSubmissionId) {
                Log::error(
                    "Questionnaire submission ID not found for Chargebee subscription",
                    [
                        "email" => $customer["email"],
                        "subscription_id" => $subscription["id"],
                        "webhook_data" => $data,
                    ],
                );
                DB::rollBack();
                return response()->json(
                    ["error" => "Questionnaire submission ID not found"],
                    400,
                );
            }

            $chargebeeItemPriceId =
                $subscription["subscription_items"][0]["item_price_id"] ?? null;

            Subscription::updateOrCreate(
                ["chargebee_subscription_id" => $subscription["id"]],
                [
                    "chargebee_customer_id" => $customer["id"],
                    "user_id" => $user->id,
                    "questionnaire_submission_id" => $questionnaireSubmissionId,
                    "chargebee_item_price_id" => $chargebeeItemPriceId,
                    "status" => strtolower($subscription["status"]),
                    "next_charge_scheduled_at" => isset(
                        $subscription["next_billing_at"],
                    )
                        ? date("Y-m-d H:i:s", $subscription["next_billing_at"])
                        : null,
                ],
            );

            DB::commit();

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
                    Prescription::where(
                        "id",
                        $localSubscription->prescription_id,
                    )->update(["status" => "cancelled"]);
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

        try {
            DB::beginTransaction();

            $localSubscription = null;
            if ($subscriptionData) {
                $localSubscription = Subscription::where(
                    "chargebee_subscription_id",
                    $subscriptionData["id"],
                )->first();
            }

            // If subscription does not exist locally, create it. This handles the initial payment case.
            if (!$localSubscription && $subscriptionData && $customerData) {
                $user = User::where("email", $customerData["email"])->first();
                if (!$user) {
                    throw new \Exception(
                        "User not found with email: " . $customerData["email"],
                    );
                }

                $questionnaireSubmissionId =
                    $subscriptionData["cf_questionnaire_submission_id"] ?? null;
                if (!$questionnaireSubmissionId) {
                    throw new \Exception(
                        "Questionnaire submission ID custom field not found.",
                    );
                }

                $chargebeeItemPriceId =
                    $subscriptionData["subscription_items"][0][
                        "item_price_id"
                    ] ?? null;

                $localSubscription = Subscription::create([
                    "chargebee_subscription_id" => $subscriptionData["id"],
                    "chargebee_customer_id" => $customerData["id"],
                    "user_id" => $user->id,
                    "questionnaire_submission_id" => $questionnaireSubmissionId,
                    "chargebee_item_price_id" => $chargebeeItemPriceId,
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

                Log::info("Created new local subscription from payment.", [
                    "subscription_id" => $localSubscription->id,
                ]);
            } elseif (!$localSubscription) {
                // If we still don't have a subscription, we can't proceed.
                throw new \Exception(
                    "Could not find or create a subscription for this payment.",
                );
            }

            $isInitialPayment = empty(
                $localSubscription->original_shopify_order_id
            );

            // Log::info("Processing payment", [
            //     "transaction_id" => $transaction["id"],
            //     "invoice_id" => $invoice["id"],
            //     "payment_type" => $isInitialPayment ? "initial" : "recurring",
            //     "subscription_id" => $localSubscription->id,
            // ]);

            if ($isInitialPayment) {
                $this->handleInitialPayment($localSubscription);
            } else {
                // $existingOrder = ProcessedRecurringOrder::where(
                //     "chargebee_invoice_id",
                //     $invoice["id"],
                // )->first();

                // if ($existingOrder) {
                //     Log::info("Invoice already processed, skipping", [
                //         "invoice_id" => $invoice["id"],
                //     ]);
                //     DB::commit();
                //     return response()->json([
                //         "message" => "Invoice already processed",
                //     ]);
                // }

                $this->handleRecurringPayment($localSubscription);
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

        // Find active prescription for this subscription
        $prescription = $subscription->prescription;

        // If no direct link, find prescription through clinical plan
        if (!$prescription && $subscription->questionnaire_submission_id) {
            $prescription = Prescription::where("status", "active")
                ->whereHas("clinicalPlan.questionnaireSubmission", function (
                    $query,
                ) use ($subscription) {
                    $query->where(
                        "id",
                        $subscription->questionnaire_submission_id,
                    );
                })
                ->first();
        }

        if ($prescription) {
            // Link prescription to subscription if not already linked
            if (!$subscription->prescription_id) {
                $subscription->update(["prescription_id" => $prescription->id]);
            }
        }
    }

    /**
     * Handle recurring payment (renewal payment)
     */
    private function handleRecurringPayment(Subscription $subscription): void
    {
        $prescription = $subscription->prescription;

        if (!$prescription || $prescription->status !== "active") {
            Log::warning("No active prescription found for recurring payment", [
                "subscription_id" => $subscription->id,
            ]);
            return;
        }

        $isReplacement = !is_null($prescription->replaces_prescription_id);
        if ($isReplacement) {
            $hasRecurring = ProcessedRecurringOrder::where(
                "prescription_id",
                $prescription->id,
            )->exists();
            if (!$hasRecurring) {
                Log::info(
                    "First payment for replacement prescription. Not decrementing refills.",
                    ["prescription_id" => $prescription->id],
                );
            } else {
                if ($prescription->refills > 0) {
                    $prescription->decrement("refills");
                }
            }
        } else {
            if ($prescription->refills > 0) {
                $prescription->decrement("refills");
            }
        }

        CreateRenewalShopifyOrderJob::dispatch($prescription->id);
        ProcessedRecurringOrder::create([
            "prescription_id" => $prescription->id,
            "processed_at" => now(),
        ]);
        $this->handleDoseProgression($prescription);
    }

    /**
     * Handle dose progression for prescriptions with dose schedules
     */
    private function handleDoseProgression(Prescription $prescription): void
    {
        $schedule = $prescription->dose_schedule;
        if (!$schedule || count($schedule) <= 1) {
            return;
        }

        $maxRefill = collect($schedule)->max("refill_number") ?? 0;
        $usedSoFar = $maxRefill - ($prescription->refills ?? 0);

        // We target the next dose index. Since 'usedSoFar' is 0-indexed count of used refills,
        // it naturally points to the next dose entry in the 0-indexed schedule array.
        $nextDoseIndex = $usedSoFar;
        if (isset($schedule[$nextDoseIndex])) {
            $currentDosePriceId =
                $prescription->subscription->chargebee_item_price_id;
            $nextDosePriceId =
                $schedule[$nextDoseIndex]["chargebee_item_price_id"];

            // Only update if the plan is actually changing
            if ($currentDosePriceId !== $nextDosePriceId) {
                UpdateSubscriptionDoseJob::dispatch(
                    $prescription->id,
                    $nextDoseIndex,
                );
            }
        }
    }
}
