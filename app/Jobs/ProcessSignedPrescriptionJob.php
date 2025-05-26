<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Services\YousignService;
use App\Services\ShopifyService;
use App\Services\SupabaseStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSignedPrescriptionJob implements ShouldQueue
{
    use Queueable, Dispatchable, SerializesModels, InteractsWithQueue;

    public $tries = 5;
    public $backoff = [60, 300, 600];

    protected $prescriptionId;
    protected $signatureRequestId;
    protected $documentId;
    protected $shopifyOrderId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $prescriptionId,
        string $signatureRequestId,
        string $documentId,
        string $shopifyOrderId
    ) {
        $this->prescriptionId = $prescriptionId;
        $this->signatureRequestId = $signatureRequestId;
        $this->documentId = $documentId;
        $this->shopifyOrderId = $shopifyOrderId;
    }

    /**
     * Execute the job.
     */
    public function handle(
        YousignService $yousignService,
        ShopifyService $shopifyService,
        SupabaseStorageService $supabaseService
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

        $uploadedPath = null;
        // Check if we have not already stored the signed prescription
        if (empty($prescription->signed_prescription_supabase_path)) {
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

            // 2. Upload to Supabase Storage and get URL
            $supabaseUrl = null;

            // Define a path for the file in Supabase bucket
            $supabasePath = "signed_prescriptions/prescription_{$prescription->id}";

            try {
                //Upload with content type for PDF
                $uploadOptions = [
                    "contentType" => "application/pdf",
                    "upsert" => false,
                ];
                $uploadedPath = $supabaseService->uploadFile(
                    $supabasePath,
                    $signedDocument,
                    $uploadOptions
                );

                if (!$uploadedPath) {
                    Log::error(
                        "failed to upload signed prescription to Supabase Storage for prescription #{$this->prescriptionId}"
                    );
                    $this->release(60 * 2); // Retry in 2 minutes
                    return;
                }
                Log::info(
                    "Successfully uploaded signed prescription to Supabase Storage.",
                    [
                        "prescription_id" => $this->prescriptionId,
                        "supabase_path" => $uploadedPath,
                    ]
                );

                // Save the Supabase path to prescription
                $prescription->signed_prescription_supabase_path = $uploadedPath;
                $prescription->save();
                Log::info(
                    "Saved Supabase path '{$uploadedPath}' to prescription #{$this->prescriptionId}"
                );
            } catch (\Exception $e) {
                Log::error(
                    "Exception during Supabase upload or saving path in ProcessSignedPrescriptionJob",
                    [
                        "prescription_id" => $this->prescriptionId,
                        "supabase_attempted_path" => $supabasePath,
                        "error" => $e->getMessage(),
                        "trace" => $e->getTraceAsString(),
                    ]
                );
                $this->release(60 * 3); // Longer backoff for exceptions
                return;
            }
        } else {
            // Use the saved URL
            $uploadedPath = $prescription->signed_prescription_supabase_path;
        }

        // Now, get signed URL to attach to Shopify order
        $expiresInSeconds = 60 * 60 * 24 * 30; // Example: 30 days
        $supabaseUrl = $supabaseService->createSignedUrl(
            $uploadedPath,
            $expiresInSeconds
        );

        if (!$supabaseUrl) {
            Log::error(
                "Failed to get Supabase URL for prescription #{$this->prescriptionId}. Releasing job.",
                ["path" => $uploadedPath]
            );
            $this->release(60 * 2); // Retry in 2 minutes
            return;
        }
        Log::info(
            "Successfully obtained Supabase URL for prescription #{$this->prescriptionId}.",
            ["url_preview" => substr($supabaseUrl, 0, 100) . "..."]
        );

        if ($supabaseUrl) {
            $orderGid = $shopifyService->formatGid($this->shopifyOrderId);
            if (!$orderGid) {
                Log::error(
                    "Failed to format Order GID from numeric ID: {$this->shopifyOrderId} for prescription #{$this->prescriptionId}. Cannot set metafield."
                );
                $this->release(60 * 10);
                return;
            }

            $metafieldNamespace = "custom";
            $metafieldKey = "prescription_url";
            $metafieldType = "url";

            $metafieldsToSet = [
                [
                    "namespace" => $metafieldNamespace,
                    "key" => $metafieldKey,
                    "type" => $metafieldType,
                    "value" => $supabaseUrl,
                ],
            ];

            $success = $shopifyService->setOrderMetafields(
                $orderGid,
                $metafieldsToSet
            );

            if (!$success) {
                Log::error(
                    "Failed to set Supabase document URL metafield on Shopify order {$orderGid} for prescription #{$this->prescriptionId}. Releasing job."
                );
                $this->release(60 * 2); // Retry in 2 mins
                return;
            }
            Log::info(
                "Successfully attached Supabase document URL to Shopify order {$orderGid} for prescription #{$this->prescriptionId}"
            );
        } else {
            Log::error(
                "Supabase URL was not obtained for prescription #{$this->prescriptionId}. Retrying"
            );
            $this->release(60);
            return;
        }

        Log::info(
            "ProcessSignedPrescriptionJob completed for prescription #{$this->prescriptionId}"
        );
    }
}
