<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Services\ShopifyService;
use App\Services\YousignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessRecurringOrderAttachmentsJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 5;
    public $backoff = [60, 300, 600];

    protected $prescriptionId;
    protected $shopifyOrderId; // The ID of the *current* recurring order

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
    public function handle(
        ShopifyService $shopifyService,
        YousignService $yousignService
    ): void {
        Log::info(
            "Processing recurring order attachments job for prescription ID: {$this->prescriptionId}, Shopify Order ID: {$this->shopifyOrderId}"
        );

        $prescription = Prescription::find($this->prescriptionId);
        if (!$prescription) {
            Log::error(
                "Prescription {$this->prescriptionId} not found in job."
            );
            $this->fail("Prescription not found.");
            return;
        }

        // --- Task 1: Attach Prescription Label ---
        try {
            if (
                !$shopifyService->attachPrescriptionLabelToOrder(
                    $prescription,
                    $this->shopifyOrderId
                )
            ) {
                Log::error("Failed to attach renewal label in job", [
                    "order_id" => $this->shopifyOrderId,
                    "prescription_id" => $this->prescriptionId,
                ]);
                $this->release(60 * 2); // Retry label attachment in 2 mins
                return;
            }
            Log::info(
                "Successfully attached label for prescription #{$this->prescriptionId} to order {$this->shopifyOrderId}"
            );
        } catch (\Exception $e) {
            Log::error("Exception attaching label in job", [
                "order_id" => $this->shopifyOrderId,
                "prescription_id" => $this->prescriptionId,
                "error" => $e->getMessage(),
            ]);
            $this->release(60 * 2); // Retry label attachment in 2 mins
            return; // Stop further processing in this attempt
        }

        // --- Task 2: Download Signed PDF and Attach ---
        $signatureRequestId = $prescription->yousign_signature_request_id;
        $documentId = $prescription->yousign_document_id;

        if (!$signatureRequestId || !$documentId) {
            Log::warning(
                "Missing Yousign IDs for prescription #{$this->prescriptionId}, cannot attach signed PDF."
            );
            // Job is successful if label attached, even if PDF can't be.
            return;
        }

        $tempPath = null;
        try {
            $bytes = $yousignService->downloadSignedDocument(
                $signatureRequestId,
                $documentId
            );

            if (!$bytes) {
                Log::warning(
                    "Could not download signed PDF for prescription #{$this->prescriptionId} in job. Will retry."
                );
                $this->release(60 * 2); // Retry download in 2 mins
                return; // Stop further processing
            }

            // Store temp file
            $tempPath =
                "prescriptions/" .
                $prescription->id .
                "_signed_" .
                uniqid() .
                ".pdf";
            Storage::put($tempPath, $bytes);
            $fullPath = Storage::path($tempPath);

            if (!file_exists($fullPath)) {
                throw new \Exception(
                    "Temporary file for signed PDF not created at {$fullPath}"
                );
            }

            // Attach PDF
            $pdfAttached = $shopifyService->attachPrescriptionToOrder(
                $this->shopifyOrderId,
                $fullPath,
                "Signed prescription #{$prescription->id}"
            );

            // Clean up temp file
            Storage::delete($tempPath);

            if (!$pdfAttached) {
                Log::error(
                    "Failed to attach signed PDF to Shopify order {$this->shopifyOrderId} in job for prescription #{$this->prescriptionId}. Will retry."
                );
                $this->release(60 * 5); // Retry attachment in 5 mins
                return; // Stop further processing
            }

            Log::info(
                "Successfully attached signed PDF for prescription #{$this->prescriptionId} to order {$this->shopifyOrderId}"
            );
        } catch (\Exception $e) {
            Log::error("Exception attaching signed PDF in job", [
                "order_id" => $this->shopifyOrderId,
                "prescription_id" => $this->prescriptionId,
                "error" => $e->getMessage(),
            ]);
            // Clean up if temp file exists
            if ($tempPath && Storage::exists($tempPath)) {
                Storage::delete($tempPath);
            }
            $this->release(60 * 5); // Retry in 5 mins
            return;
        }
    }
}
