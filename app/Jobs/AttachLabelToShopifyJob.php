<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AttachLabelToShopifyJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 5;
    public $backoff = [60, 300, 600];

    protected $prescriptionId;
    protected $shopifyOrderId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $prescriptionId, string $shopifyOrderId)
    {
        $this->prescriptionId = $prescriptionId;
        $this->shopifyOrderId = $shopifyOrderId;
    }

    /**
     * Execute the job.
     */
    public function handle(ShopifyService $shopifyService): void
    {
        Log::info(
            "Attaching metafields job for prescription ID: {$this->prescriptionId} to Shopify Order ID: {$this->shopifyOrderId}"
        );

        $prescription = Prescription::with([
            "patient",
            "prescriber",
            "clinicalPlan",
        ])->find($this->prescriptionId);
        if (!$prescription) {
            Log::error(
                "Prescription {$this->prescriptionId} not found in AttachLabelToShopifyJob."
            );
            $this->fail("Prescription not found.");
            return;
        }

        if (empty($this->shopifyOrderId)) {
            Log::error(
                "Shopify order ID missing in call to attach metafields."
            );
            $this->fail("Shopify order ID not found.");
            return;
        }

        try {
            $orderGid = $shopifyService->formatGid($this->shopifyOrderId);
            $metafieldsToSet = [];

            // 1. Prepare Prescription Label Metafield
            $patient = $prescription->patient;
            $prescriber = $prescription->prescriber;

            if (!$patient || !$prescriber) {
                Log::error(
                    "Patient or Prescriber data missing for prescription label metafield.",
                    ["prescription_id" => $prescription->id]
                );
                // Fail
                $this->release(60);
                return;
            } else {
                $schedule = $prescription->dose_schedule ?? [];
                $maxRefill = collect($schedule)->max("refill_number") ?? 0;
                $remaining = $prescription->refills ?? 0;
                $usedSoFar = $maxRefill > 0 ? $maxRefill - $remaining : 0;
                $entry = collect($schedule)->firstWhere(
                    "refill_number",
                    $usedSoFar
                );
                $currentDose = $entry["dose"] ?? $prescription->dose;

                $labelData = [
                    "prescription_id" => $prescription->id,
                    "patient" => [
                        "name" => $patient->name ?? "",
                        "address" => $patient->address ?? "",
                    ],
                    "medication" => [
                        "name" => $prescription->medication_name ?? "",
                        "dose" => $currentDose ?? "",
                    ],
                    "directions" => $prescription->directions ?? "",
                    "refill_information" => "NO REFILLS",
                    "prescriber" => [
                        "name" => $prescriber->name ?? "",
                        "registration_number" =>
                            $prescriber->registration_number ?? "",
                    ],
                ];

                $jsonLabelData = json_encode($labelData);
                if ($jsonLabelData === false) {
                    Log::error(
                        "Failed to encode prescription label data to JSON.",
                        [
                            "prescription_id" => $prescription->id,
                        ]
                    );
                } else {
                    $metafieldsToSet[] = [
                        "namespace" => "custom",
                        "key" => "prescription_label_json",
                        "type" => "json",
                        "value" => $jsonLabelData,
                    ];
                }
            }

            // 2. Prepare Questionnaire Submission URL Metafield
            if (
                $prescription->clinicalPlan &&
                $prescription->clinicalPlan->questionnaire_submission_id &&
                $prescription->patient_id
            ) {
                $frontendUrl = rtrim(config("app.frontend_url"), "/");
                $questionnaireUrl =
                    $frontendUrl .
                    "/provider/patients/" .
                    $prescription->patient_id .
                    "/questionnaires/" .
                    $prescription->clinicalPlan->questionnaire_submission_id;

                $metafieldsToSet[] = [
                    "namespace" => "custom",
                    "key" => "questionnaire_submission_url",
                    "type" => "url",
                    "value" => $questionnaireUrl,
                ];
                Log::info(
                    "Prepared questionnaire URL metafield: {$questionnaireUrl} for order {$orderGid}"
                );
            } else {
                Log::warning(
                    "Could not generate questionnaire submission URL for prescription #{$this->prescriptionId}. Clinical plan, submission ID, or patient ID might be missing.",
                    [
                        "clinical_plan_exists" => !empty(
                            $prescription->clinicalPlan
                        ),
                        "submission_id_exists" => !empty(
                            $prescription->clinicalPlan
                                ->questionnaire_submission_id
                        ),
                        "patient_id_exists" => !empty(
                            $prescription->patient_id
                        ),
                    ]
                );
            }

            // 3. Set all collected metafields
            if (!empty($metafieldsToSet)) {
                $success = $shopifyService->setOrderMetafields(
                    $orderGid,
                    $metafieldsToSet
                );

                if (!$success) {
                    Log::error(
                        "ShopifyService::setOrderMetafields failed for prescription #{$this->prescriptionId} and order {$orderGid}. Releasing job."
                    );
                    $this->release(60); // Retry in 1 minute
                    return;
                }
            } else {
                Log::info(
                    "No metafields to set for prescription #{$this->prescriptionId} and order {$orderGid}."
                );
                // Fail ?
            }
        } catch (\Throwable $e) {
            Log::error("Exception during AttachLabelToShopifyJob", [
                "prescription_id" => $this->prescriptionId,
                "shopify_order_id" => $this->shopifyOrderId,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            $this->release(60 * 2); // Retry in 2 minutes
        }
    }
}
