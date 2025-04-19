<?php

namespace App\Jobs;

use App\Models\Prescription;
use App\Services\YousignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InitiateYousignSignatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    public function handle(YousignService $yousignService): void
    {
        Log::info(
            "Initiating Yousign signature job for prescription ID: {$this->prescriptionId}"
        );

        // Use a transaction to ensure finding and updating the prescription is atomic
        // regarding the check for existing signature request ID.
        DB::beginTransaction();
        try {
            $prescription = Prescription::lockForUpdate()->find(
                $this->prescriptionId
            );

            if (!$prescription) {
                Log::error(
                    "Prescription {$this->prescriptionId} not found in InitiateYousignSignatureJob."
                );
                DB::rollBack(); // Rollback before failing
                $this->fail("Prescription not found.");
                return;
            }

            // Idempotency Check: If already has a request ID, assume previous attempt succeeded partially or fully.
            if (!empty($prescription->yousign_signature_request_id)) {
                Log::warning(
                    "Prescription #{$this->prescriptionId} already has a Yousign request ID ({$prescription->yousign_signature_request_id}). Skipping signature initiation."
                );
                DB::commit(); // Commit as no action needed
                return;
            }

            // Call the Yousign service to handle the entire flow
            $yousignSRId = $yousignService->sendForSignature($prescription);

            if (!$yousignSRId) {
                // The service method failed, likely logged the error. Release for retry.
                Log::error(
                    "YousignService::sendForSignature failed for prescription #{$this->prescriptionId}. Releasing job."
                );
                DB::rollBack(); // Rollback as the update won't happen
                $this->release(60 * 5); // Retry in 5 minutes
                return;
            }

            // Update the prescription record with the request ID
            $prescription->yousign_signature_request_id = $yousignSRId;
            $prescription->save();

            DB::commit(); // Commit the update

            Log::info(
                "Successfully initiated Yousign signature request ({$yousignSRId}) for prescription #{$this->prescriptionId}"
            );
        } catch (\Throwable $e) {
            // Catch any unexpected exception
            DB::rollBack();
            Log::error("Exception during InitiateYousignSignatureJob", [
                "prescription_id" => $this->prescriptionId,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            $this->release(60 * 5); // Retry in 5 minutes
        }
    }
}
