<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Notifications\PrescriptionSignedNotification;
use App\Services\ShopifyService;
use App\Services\YousignService;
use App\Services\SupabaseStorageService;
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
        $subscription = $prescription->subscription;
        $orderId = $subscription->original_shopify_order_id;

        // Define a path for the file in Supabase bucket
        $supabasePath = "signed_prescriptions/order_{$orderId}/prescription_{$prescription->id}";

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

            // Get signed URL for temporary access (recommended)
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
        } catch (\Exception $e) {
            Log::error("Exception attaching files to Shopify order in job", [
                "prescription_id" => $prescription->id,
                "order_id" => $orderId,
                "error" => $e->getMessage(),
            ]);
            // Let the queue retry
            $this->release(60 * 5); // Release back to queue, retry in 5 mins
            return;
        }

        // 3. Attach Supabase URL to Shopify order metafield
        if (
            $supabaseUrl &&
            $subscription &&
            $subscription->original_shopify_order_id
        ) {
            $orderGid = $shopifyService->formatGid(
                $subscription->original_shopify_order_id
            );
            if (!$orderGid) {
                Log::error(
                    "Failed to format Order GID from numeric ID: {$subscription->original_shopify_order_id} for prescription #{$this->prescriptionId}. Cannot set metafield."
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
        } elseif (!$supabaseUrl) {
            Log::warning(
                "Supabase URL was not obtained, skipping Shopify metafield update for prescription #{$this->prescriptionId}."
            );
        } else {
            // Implies $subscription or $subscription->original_shopify_order_id was missing
            Log::warning(
                "No Shopify order ID found for prescription #{$prescription->id}, cannot attach Supabase URL to Shopify."
            );
        }

        // 3. Send notification to patient
        // if ($prescription->patient) {
        //     try {
        //         $notificationCacheKey =
        //             "signed_notification_sent_" . $prescription->id;
        //         if (!cache()->has($notificationCacheKey)) {
        //             $prescription->patient->notify(
        //                 new PrescriptionSignedNotification($prescription)
        //             );
        //             cache()->put(
        //                 $notificationCacheKey,
        //                 true,
        //                 now()->addHours(24)
        //             ); // Prevent re-sending for 24h
        //             Log::info(
        //                 "Dispatched PrescriptionSignedNotification for prescription #{$this->prescriptionId}"
        //             );
        //         } else {
        //             Log::info(
        //                 "Skipping notification for prescription #{$this->prescriptionId}, already sent."
        //             );
        //         }
        //     } catch (\Exception $notifyError) {
        //         Log::error(
        //             "Failed to dispatch PrescriptionSignedNotification",
        //             [
        //                 "prescription_id" => $this->prescriptionId,
        //                 "patient_id" => $prescription->patient_id,
        //                 "error" => $notifyError->getMessage(),
        //             ]
        //         );
        //         // Don't fail the job for notification error
        //     }
        // } else {
        //     Log::warning(
        //         "Could not send notification for prescription #{$this->prescriptionId}: Patient data missing."
        //     );
        // }

        Log::info(
            "ProcessSignedPrescriptionJob completed for prescription #{$this->prescriptionId}"
        );
    }
}
