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

class AttachInitialLabelToShopifyJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 5;
    public $backoff = [60, 300, 600];

    protected $prescriptionId;

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
    public function handle(ShopifyService $shopifyService): void
    {
        Log::info(
            "Attaching initial label job for prescription ID: {$this->prescriptionId}"
        );

        $prescription = Prescription::find($this->prescriptionId);
        if (!$prescription) {
            Log::error(
                "Prescription {$this->prescriptionId} not found in AttachInitialLabelToShopifyJob."
            );
            $this->fail("Prescription not found.");
            return;
        }

        try {
            // Call the Shopify service method which handles label generation and attachment
            $success = $shopifyService->attachPrescriptionLabelToOrder(
                $prescription
            );

            if (!$success) {
                // The service method failed, likely logged the error. Release for retry.
                Log::error(
                    "ShopifyService::attachPrescriptionLabelToOrder failed for prescription #{$this->prescriptionId}. Releasing job."
                );
                $this->release(60 * 2); // Retry in 2 minutes
                return;
            }

            Log::info(
                "Successfully attached initial prescription label for prescription #{$this->prescriptionId} to initial Shopify order"
            );
        } catch (\Throwable $e) {
            // Catch any unexpected exception
            Log::error("Exception during AttachInitialLabelToShopifyJob", [
                "prescription_id" => $this->prescriptionId,
                "shopify_order_id" => $this->shopifyOrderId,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            $this->release(60 * 5); // Retry in 5 minutes
        }
    }
}
