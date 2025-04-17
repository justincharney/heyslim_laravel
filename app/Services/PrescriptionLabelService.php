<?php

namespace App\Services;

use App\Models\Prescription;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Facades\Pdf;

class PrescriptionLabelService
{
    /**
     * Save the prescription label PDF to a file
     *
     * @param Prescription $prescription The prescription to generate a label for
     * @return string|null The path to the saved file, or null if generation failed
     */
    public function saveLabel(Prescription $prescription): ?string
    {
        try {
            $filePath = storage_path(
                "app/prescriptions/label_{$prescription->id}.pdf"
            );

            // Ensure directory exists
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            Pdf::view("pdfs.prescription_label", [
                "prescription" => $prescription,
                "patient" => $prescription->patient,
                "prescriber" => $prescription->prescriber,
            ])
                ->paperSize(70, 35, "mm")
                ->margins(0, 0, 0, 0)
                ->save($filePath);

            return $filePath;
        } catch (\Throwable $e) {
            Log::error("Error saving prescription label PDF", [
                "error" => $e->getMessage(),
                "prescription_id" => $prescription->id,
            ]);
            return null;
        }
    }
}
