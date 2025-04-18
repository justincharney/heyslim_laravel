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

class ProcessSignedPrescriptionJob implements ShouldQueue
{
    use Queueable, Dispatchable, SerializesModels, InteractsWithQueue;

    public $tries = 5;
    public $backoff = [60, 300, 600];

    protected $prescriptionId;
    protected $signatureRequestId;
    protected $documentId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $prescriptionId,
        string $signatureRequestId,
        string $documentId
    ) {
        $this->prescriptionId = $prescriptionId;
        $this->signatureRequestId = $signatureRequestId;
        $this->documentId = $documentId;
    }

    /**
     * Execute the job.
     */
    public function handle(
        YousignService $yousignService,
        ShopifyService $shopifyService
    ): void {
        Log::info(
            "Processing signed prescription job for prescription ID: {$this->prescriptionId}"
        );

        $prescription = Prescription::find($this->prescriptionId);
        if (!$prescription) {
            Log::error(
                "Prescription {$this->prescriptionId} not found in job."
            );
            $this->fail("Prescription not found."); // Mark job as failed
            return;
        }

        // 1. Download the signed document
        $signedDocument = $yousignService->downloadSignedDocument(
            $this->signatureRequestId,
            $this->documentId
        );
        if (!$signedDocument) {
            Log::error(
                "Failed to download signed document in job for SR ID: {$this->signatureRequestId}"
            );
            // Let the queue retry
            $this->release(60 * 2); // Release back to queue, retry in 2 mins
            return;
        }
        Log::info(
            "Successfully downloaded signed document for prescription ID: {$this->prescriptionId}"
        );

        // 2. Attach to Shopify order
        $subscription = $prescription->subscription;
        if (!$subscription || !$subscription->original_shopify_order_id) {
            Log::warning(
                "No Shopify order ID found for prescription #{$prescription->id} in job, cannot attach document."
            );
            // Don't fail the job if there's no order to attach to
            return;
        }

        $orderId = $subscription->original_shopify_order_id;
        $tempPath = null;
        $allAttachmentsSuccessful = true;

        try {
            // Store the document temporarily
            $tempPath =
                "prescriptions/" .
                $prescription->id .
                "_signed_" .
                uniqid() .
                ".pdf";
            Storage::put($tempPath, $signedDocument);
            $fullPath = Storage::path($tempPath);

            if (!file_exists($fullPath)) {
                throw new \Exception(
                    "Temporary file not created at {$fullPath}"
                );
            }

            $success = $shopifyService->attachPrescriptionToOrder(
                $orderId,
                $fullPath,
                "Signed prescription #{$prescription->id}"
            );

            // Clean up the temporary file regardless of success
            Storage::delete($tempPath);

            if (!$success) {
                Log::error(
                    "Failed to attach PRESCRIPTION to Shopify order {$orderId} in job for prescription #{$prescription->id}."
                );
                $allAttachmentsSuccessful = false;
            } else {
                Log::info(
                    "Successfully attached PRESCRIPTION to Shopify order {$orderId} for prescription #{$prescription->id}"
                );
            }

            // Attach prescription label
            $labelAttached = $shopifyService->attachPrescriptionLabelToOrder(
                $prescription,
                $orderId
            );

            if (!$labelAttached) {
                Log::error(
                    "Failed to attach LABEL PDF to Shopify order {$orderId} for prescription #{$prescription->id}."
                );
                $allAttachmentsSuccessful = false;
            } else {
                Log::info(
                    "Successfully attached LABEL PDF to Shopify order {$orderId} for prescription #{$prescription->id}"
                );
            }

            if (!$allAttachmentsSuccessful) {
                // If either attachment failed, retry the job
                $this->release(60 * 5); // Retry attachments in 5 mins
                return;
            }

            Log::info(
                "All attachments successful for order {$orderId}, prescription #{$prescription->id}. Job complete."
            );
        } catch (\Exception $e) {
            Log::error("Exception attaching files to Shopify order in job", [
                "prescription_id" => $prescription->id,
                "order_id" => $orderId,
                "error" => $e->getMessage(),
            ]);
            // Clean up if temp file exists
            if ($tempPath && Storage::exists($tempPath)) {
                Storage::delete($tempPath);
            }
            // Let the queue retry
            $this->release(60 * 5); // Release back to queue, retry in 5 mins
            return;
        }
    }
}
