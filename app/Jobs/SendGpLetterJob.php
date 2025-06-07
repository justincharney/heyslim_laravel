<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\GpLetterService;
use App\Notifications\GpLetterNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Prescription;
use Illuminate\Support\Facades\Log;

class SendGpLetterJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

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
    public function handle(GpLetterService $gpLetterService): void
    {
        $prescription = Prescription::with("patient")->find(
            $this->prescriptionId
        );

        if (!$prescription) {
            Log::error(
                "SendGpLetterJob: Prescription not found for ID: {$this->prescriptionId}. Job will fail."
            );
            $this->fail("Prescription not found: {$this->prescriptionId}");
            return;
        }

        if (!$prescription->patient) {
            Log::error(
                "SendGpLetterJob: Patient not found for prescription ID: {$this->prescriptionId}. Job will fail."
            );
            $this->fail(
                "Patient not found for prescription: {$this->prescriptionId}"
            );
            return;
        }

        try {
            $pdfBinary = $gpLetterService->generatePdfContent($prescription);

            if (!$pdfBinary) {
                Log::error(
                    "SendGpLetterJob: Failed to generate PDF content for prescription ID: {$this->prescriptionId}. Releasing job."
                );
                $this->release(60); // Release for 1 minute
                return;
            }

            // Decode the document
            // $pdfBinary = base64_decode($pdfBinary);

            $pdfFilename = "GP_Letter_Prescription_{$prescription->id}.pdf";

            $prescription->patient->notify(
                new GpLetterNotification(
                    $prescription,
                    $pdfBinary,
                    $pdfFilename
                )
            );
        } catch (\Exception $e) {
            Log::error(
                "SendGpLetterJob: Exception occurred for prescription ID: {$this->prescriptionId}",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            $this->release(60); // Release for 1 minute
        }
    }
}
