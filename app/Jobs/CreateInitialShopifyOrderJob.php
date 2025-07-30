<?php

namespace App\Jobs;

use App\Config\ShopifyProductMapping;
use App\Jobs\UpdateSubscriptionDoseJob;
use App\Jobs\ProcessSignedPrescriptionJob;
use App\Jobs\AttachLabelToShopifyJob;
use App\Models\Prescription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ShopifyService;
use App\Services\DoseProgressionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CreateInitialShopifyOrderJob implements ShouldQueue
{
    use Queueable;

    protected int $prescriptionId;
    protected ?string $chargebeeInvoiceId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $prescriptionId,
        ?string $chargebeeInvoiceId = null,
    ) {
        $this->prescriptionId = $prescriptionId;
        $this->chargebeeInvoiceId = $chargebeeInvoiceId;
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

            // Check if order already exists for initial orders only
            if (
                !$this->chargebeeInvoiceId &&
                $subscription->original_shopify_order_id
            ) {
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
                $this->chargebeeInvoiceId !== null,
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
            $isRenewal = $this->chargebeeInvoiceId !== null;
            $orderNote = $isRenewal
                ? "Renewal order for prescription #{$prescription->id} (Invoice: {$this->chargebeeInvoiceId})"
                : "Order created for prescription #{$prescription->id}";

            $metafields = [
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
            ];

            if ($isRenewal) {
                $metafields[] = [
                    "namespace" => "renewal",
                    "key" => "chargebee_invoice_id",
                    "value" => $this->chargebeeInvoiceId,
                    "type" => "single_line_text_field",
                ];
            }

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
                "note" => $orderNote,
                "metafields" => $metafields,
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
                DB::beginTransaction();
                try {
                    if ($this->chargebeeInvoiceId) {
                        // This is a renewal
                        Log::info(
                            "Successfully created renewal Shopify order for prescription",
                            [
                                "prescription_id" => $this->prescriptionId,
                                "shopify_order_id" => $shopifyOrderId,
                                "chargebee_invoice_id" =>
                                    $this->chargebeeInvoiceId,
                            ],
                        );
                    } else {
                        // This is an initial order - update subscription
                        $subscription->update([
                            "original_shopify_order_id" => $shopifyOrderId,
                        ]);

                        Log::info(
                            "Successfully created initial Shopify order for prescription",
                            [
                                "prescription_id" => $this->prescriptionId,
                                "shopify_order_id" => $shopifyOrderId,
                                "subscription_id" => $subscription->id,
                            ],
                        );
                    }

                    // Schedule dose progression if prescription has multiple doses
                    $this->scheduleDoseProgression($prescription);

                    DB::commit();

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
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("Failed to process Shopify order creation", [
                        "prescription_id" => $this->prescriptionId,
                        "shopify_order_id" => $shopifyOrderId,
                        "is_renewal" => $this->chargebeeInvoiceId !== null,
                        "error" => $e->getMessage(),
                    ]);
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
     * Uses the CURRENT dose for renewal orders
     */
    private function getProductVariantForPrescription(
        Prescription $prescription,
        bool $isRenewal = false,
    ): ?string {
        // For renewals, calculate current dose based on refills used
        // For initial orders, use the first dose in the schedule
        $doseSchedule = $prescription->dose_schedule;
        if ($doseSchedule) {
            $doseIndex = 0;

            if ($isRenewal) {
                // For renewals, calculate which refill_number to order based on remaining refills
                // Note: refills are now decremented BEFORE order creation
                $maxRefill = collect($doseSchedule)->max("refill_number") ?? 0;
                $refillsRemaining = $prescription->refills ?? 0;
                $refillNumberToOrder = $maxRefill - $refillsRemaining;

                // Since refill_number corresponds to array index, access directly
                if (
                    $refillNumberToOrder >= 0 &&
                    $refillNumberToOrder < count($doseSchedule) &&
                    isset($doseSchedule[$refillNumberToOrder]) &&
                    $doseSchedule[$refillNumberToOrder]["refill_number"] ===
                        $refillNumberToOrder
                ) {
                    $doseIndex = $refillNumberToOrder;
                } else {
                    // For renewals, if dose calculation is invalid (out of bounds), return null
                    Log::warning(
                        "Renewal order dose calculation out of bounds",
                        [
                            "prescription_id" => $prescription->id,
                            "max_refill" => $maxRefill,
                            "refills_remaining" => $refillsRemaining,
                            "refill_number_to_order" => $refillNumberToOrder,
                            "dose_schedule_count" => count($doseSchedule),
                            "is_renewal" => $isRenewal,
                        ],
                    );
                    return null;
                }

                Log::info("Renewal order dose calculation", [
                    "prescription_id" => $prescription->id,
                    "max_refill" => $maxRefill,
                    "refills_remaining" => $refillsRemaining,
                    "refill_number_to_order" => $refillNumberToOrder,
                    "dose_index" => $doseIndex,
                    "is_renewal" => $isRenewal,
                ]);
            }

            if (isset($doseSchedule[$doseIndex])) {
                $dose = $doseSchedule[$doseIndex];
                $medicationName = $prescription->medication_name;

                Log::info("Attempting to find Shopify variant", [
                    "prescription_id" => $prescription->id,
                    "medication_name" => $medicationName,
                    "dose_from_schedule" => $dose["dose"],
                    "dose_index" => $doseIndex,
                    "full_dose_info" => $dose,
                    "is_renewal" => $isRenewal,
                ]);

                // First check if shopify_variant_gid is directly available in the dose schedule
                if (isset($dose["shopify_variant_gid"])) {
                    $variantId = $dose["shopify_variant_gid"];

                    Log::info(
                        "Found Shopify variant directly from dose schedule",
                        [
                            "prescription_id" => $prescription->id,
                            "medication_name" => $medicationName,
                            "dose" => $dose["dose"],
                            "dose_index" => $doseIndex,
                            "is_renewal" => $isRenewal,
                            "shopify_variant_id" => $variantId,
                        ],
                    );
                    return $variantId;
                }

                // Fallback: try to find variant using medication mapping
                $variantId = $this->getVariantForMedicationAndDose(
                    $medicationName,
                    $dose["dose"],
                );

                if ($variantId) {
                    Log::info(
                        "Found Shopify variant using dose from schedule",
                        [
                            "prescription_id" => $prescription->id,
                            "medication_name" => $medicationName,
                            "dose" => $dose["dose"],
                            "dose_index" => $doseIndex,
                            "is_renewal" => $isRenewal,
                            "shopify_variant_id" => $variantId,
                        ],
                    );
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
        $doseProgressionService = app(DoseProgressionService::class);
        $doseProgressionService->progressDose($prescription);
    }
}
