<?php

namespace App\Jobs;

use App\Config\ShopifyProductMapping;
use App\Jobs\UpdateSubscriptionDoseJob;
use App\Jobs\ProcessSignedPrescriptionJob;
use App\Models\Prescription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CreateInitialShopifyOrderJob implements ShouldQueue
{
    use Queueable;

    protected int $prescriptionId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $prescriptionId)
    {
        $this->prescriptionId = $prescriptionId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Find the prescription with its relationships
            $prescription = Prescription::with([
                "clinicalPlan.questionnaireSubmission.subscription",
                "patient",
            ])->find($this->prescriptionId);

            if (!$prescription) {
                Log::error(
                    "Prescription not found for CreateInitialShopifyOrderJob",
                    [
                        "prescription_id" => $this->prescriptionId,
                    ],
                );
                return;
            }

            // Get the subscription via the questionnaire submission
            $subscription =
                $prescription->clinicalPlan?->questionnaireSubmission
                    ?->subscription;

            // Fallback: try to find subscription directly by prescription_id
            if (!$subscription) {
                $subscription = Subscription::where(
                    "prescription_id",
                    $this->prescriptionId,
                )->first();
            }

            if (!$subscription) {
                Log::error("No subscription found for prescription", [
                    "prescription_id" => $this->prescriptionId,
                ]);
                return;
            }

            // Check if order already exists
            if ($subscription->original_shopify_order_id) {
                Log::info("Shopify order already exists for subscription", [
                    "prescription_id" => $this->prescriptionId,
                    "subscription_id" => $subscription->id,
                    "shopify_order_id" =>
                        $subscription->original_shopify_order_id,
                ]);
                return;
            }

            $shopifyService = app(ShopifyService::class);

            // Create Shopify order for the prescription
            $productVariantId = $this->getProductVariantForPrescription(
                $prescription,
            );

            if (!$productVariantId) {
                Log::error(
                    "Could not determine product variant for prescription",
                    [
                        "prescription_id" => $this->prescriptionId,
                        "medication_name" => $prescription->medication_name,
                    ],
                );
                return;
            }

            // Prepare order data
            $patient = User::find($prescription->patient_id);
            $orderData = [
                "lineItems" => [
                    [
                        "variantId" => $productVariantId,
                        "quantity" => 1,
                    ],
                ],
                "customer" => [
                    "toAssociate" => [
                        "email" => $patient->email,
                    ],
                ],
                "financialStatus" => "PAID", // Mark as paid since Chargebee handles payment
                "note" => "Order created for prescription #{$prescription->id}",
                "metafields" => [
                    [
                        "namespace" => "prescription",
                        "key" => "prescription_id",
                        "value" => (string) $prescription->id,
                        "type" => "single_line_text_field",
                    ],
                    [
                        "namespace" => "subscription",
                        "key" => "chargebee_subscription_id",
                        "value" => $subscription->chargebee_subscription_id,
                        "type" => "single_line_text_field",
                    ],
                ],
            ];

            // // Log order data before creating the order
            // Log::info("Creating Shopify order for prescription", [
            //     "prescription_id" => $this->prescriptionId,
            //     "order_data" => $orderData,
            //     "product_variant_id" => $productVariantId,
            // ]);

            // Create the Shopify order
            $order = $shopifyService->createOrder($orderData);

            // Log::info("Shopify createOrder response", [
            //     "prescription_id" => $this->prescriptionId,
            //     "order_response" => $order,
            //     "order_is_null" => is_null($order),
            //     "order_has_id" => $order ? isset($order["id"]) : false,
            // ]);

            if (!$order || !isset($order["id"])) {
                Log::error("Failed to create Shopify order for prescription", [
                    "prescription_id" => $this->prescriptionId,
                    "order_response" => $order,
                    "order_data_sent" => $orderData,
                ]);
                return;
            }

            // Extract order ID from order response
            $shopifyOrderId = $this->extractOrderIdFromOrder($order);

            if ($shopifyOrderId) {
                // Update subscription with Shopify order ID
                DB::beginTransaction();
                try {
                    $subscription->update([
                        "original_shopify_order_id" => $shopifyOrderId,
                    ]);

                    DB::commit();

                    // Log::info(
                    //     "Successfully created initial Shopify order for prescription",
                    //     [
                    //         "prescription_id" => $this->prescriptionId,
                    //         "shopify_order_id" => $shopifyOrderId,
                    //         "subscription_id" => $subscription->id,
                    //     ],
                    // );

                    // Dispatch job to attach prescription label to the order
                    AttachLabelToShopifyJob::dispatch(
                        $this->prescriptionId,
                        $shopifyOrderId,
                    );

                    // Dispatch job to attach the prescription to the order
                    ProcessSignedPrescriptionJob::dispatch(
                        $this->prescriptionId,
                        $shopifyOrderId,
                    );

                    // Schedule dose progression if prescription has multiple doses
                    $this->scheduleDoseProgression($prescription);
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error(
                        "Failed to update subscription with Shopify order ID",
                        [
                            "prescription_id" => $this->prescriptionId,
                            "shopify_order_id" => $shopifyOrderId,
                            "error" => $e->getMessage(),
                        ],
                    );
                }
            } else {
                Log::error(
                    "Could not extract order ID from Shopify cart response",
                    [
                        "prescription_id" => $this->prescriptionId,
                        "cart_response" => $cart,
                    ],
                );
            }
        } catch (\Exception $e) {
            Log::error("Exception in CreateInitialShopifyOrderJob", [
                "prescription_id" => $this->prescriptionId,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Get the appropriate Shopify product variant for the prescription
     * Uses the FIRST dose from the dose schedule for initial order
     */
    private function getProductVariantForPrescription(
        Prescription $prescription,
    ): ?string {
        // First, try to get variant based on the first dose in the schedule
        $doseSchedule = $prescription->dose_schedule;
        if ($doseSchedule && isset($doseSchedule[0])) {
            $firstDose = $doseSchedule[0];
            $medicationName = $prescription->medication_name;

            $variantId = $this->getVariantForMedicationAndDose(
                $medicationName,
                $firstDose["dose"],
            );

            if ($variantId) {
                Log::info(
                    "Found Shopify variant using first dose from schedule",
                    [
                        "prescription_id" => $prescription->id,
                        "medication_name" => $medicationName,
                        "first_dose" => $firstDose["dose"],
                        "shopify_variant_id" => $variantId,
                    ],
                );
                return $variantId;
            }
        }

        // Fallback: Get the subscription to find the Chargebee item price
        $subscription =
            $prescription->clinicalPlan?->questionnaireSubmission
                ?->subscription;

        if ($subscription) {
            // Get product variant by Chargebee item price from subscription
            $chargebeeItemPriceId = $subscription->chargebee_item_price_id;

            if ($chargebeeItemPriceId) {
                $variantId = ShopifyProductMapping::getShopifyVariantByChargebeeItemPrice(
                    $chargebeeItemPriceId,
                );

                if ($variantId) {
                    // Log::info(
                    //     "Found Shopify variant using Chargebee item price mapping",
                    //     [
                    //         "prescription_id" => $prescription->id,
                    //         "chargebee_item_price_id" => $chargebeeItemPriceId,
                    //         "shopify_variant_id" => $variantId,
                    //     ],
                    // );
                    return $variantId;
                }
            }
        }

        // No Shopify variant found
        Log::warning("No product variant mapping found for prescription", [
            "prescription_id" => $prescription->id,
            "medication_name" => $prescription->medication_name,
        ]);

        return null;
    }

    /**
     * Extract order ID from Shopify order response
     */
    private function extractOrderIdFromOrder(array $order): ?string
    {
        if (isset($order["id"])) {
            // Extract numeric ID from GID format
            return str_replace("gid://shopify/Order/", "", $order["id"]);
        }

        return null;
    }

    /**
     * Get the Chargebee item price ID from the subscription
     */
    private function getChargebeeItemPriceFromSubscription(
        Subscription $subscription,
    ): ?string {
        // First check if we have the item price ID stored directly
        if ($subscription->chargebee_item_price_id) {
            return $subscription->chargebee_item_price_id;
        }

        return null;
    }

    /**
     * Get Shopify variant for specific medication and dose
     */
    private function getVariantForMedicationAndDose(
        string $medicationName,
        string $doseStrength,
    ): ?string {
        // Get the product GID for this medication
        $productGid = ShopifyProductMapping::getProductId($medicationName);

        if (!$productGid) {
            return null;
        }

        // Get all variants for this product
        $variants = ShopifyProductMapping::getProductVariantsByGid($productGid);

        // Find the variant that matches the dose strength
        foreach ($variants as $variant) {
            if ($variant["dose"] === $doseStrength) {
                return $variant["shopify_variant_gid"] ?? null;
            }
        }

        return null;
    }

    /**
     * Schedule dose progression based on prescription dose schedule
     */
    private function scheduleDoseProgression(Prescription $prescription): void
    {
        $doseSchedule = $prescription->dose_schedule;

        if (!$doseSchedule || count($doseSchedule) <= 1) {
            Log::info("No dose progression needed for prescription", [
                "prescription_id" => $prescription->id,
                "dose_count" => count($doseSchedule),
            ]);
            return;
        }

        UpdateSubscriptionDoseJob::dispatch($prescription->id, 1);

        Log::info("Scheduled next dose", [
            "prescription_id" => $prescription->id,
            "next_dose" => $doseSchedule[1]["dose"] ?? "unknown",
        ]);
    }
}
