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
    public function handle(
        ShopifyService $shopifyService,
        SupabaseStorageService $supabaseService
    ): void {
        Log::info(
            "Processing signed prescription attachment job for prescription ID: {$this->prescriptionId}, order: {$this->shopifyOrderId}"
        );

        $prescription = Prescription::find($this->prescriptionId);
        if (!$prescription) {
            Log::error(
                "Prescription {$this->prescriptionId} not found in job."
            );
            $this->fail("Prescription not found."); // Mark job as failed
            return;
        }

        // Check if we have the signed prescription path
        if (empty($prescription->signed_prescription_supabase_path)) {
            Log::error(
                "Prescription {$this->prescriptionId} does not have signed_prescription_supabase_path set."
            );
            $this->fail("Signed prescription Supabase path not found.");
            return;
        }

        // Generate signed URL for the Supabase file
        $expiresInSeconds = 60 * 60 * 24 * 30; // 30 days
        $supabaseUrl = $supabaseService->createSignedUrl(
            $prescription->signed_prescription_supabase_path,
            $expiresInSeconds
        );

        if (!$supabaseUrl) {
            Log::error(
                "Failed to get Supabase URL for prescription #{$this->prescriptionId}. Releasing job.",
                ["path" => $prescription->signed_prescription_supabase_path]
            );
            $this->release(60 * 2); // Retry in 2 minutes
            return;
        }

        Log::info(
            "Successfully obtained Supabase URL for prescription #{$this->prescriptionId}.",
            ["url_preview" => substr($supabaseUrl, 0, 100) . "..."]
        );

        // Attach URL to Shopify order
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
            $this->release(60); // Retry in 1 mins
            return;
        }

        Log::info(
            "Successfully attached Supabase document URL to Shopify order {$orderGid} for prescription #{$this->prescriptionId}"
        );

        Log::info(
            "ProcessSignedPrescriptionJob completed for prescription #{$this->prescriptionId}"
        );
    }
}
