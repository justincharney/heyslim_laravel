<?php

namespace App\Services;

use App\Models\Prescription;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\Storage;

class GpLetterService
{
    /**
     * Generates the PDF content for the GP letter.
     *
     * @param Prescription $prescription
     * @param array $viewAnswers An array of processed answers from the questionnaire
     * @return string|null Binary PDF content or null on failure.
     */
    public function generatePdfContent(
        Prescription $prescription,
        array $viewAnswers
    ): ?string {
        try {
            // Ensure necessary related data is loaded for the PDF view
            $prescription->load(["patient", "prescriber", "clinicalPlan"]);

            if (
                !$prescription->patient ||
                !$prescription->prescriber ||
                !$prescription->clinicalPlan
            ) {
                Log::error(
                    "Missing related data for GP letter PDF content generation.",
                    ["prescription_id" => $prescription->id]
                );
                return null;
            }

            return Pdf::view("pdfs.gp_letter", [
                "prescription" => $prescription,
                "patient" => $prescription->patient,
                "prescriber" => $prescription->prescriber,
                "clinicalPlan" => $prescription->clinicalPlan,
                "answers" => $viewAnswers,
                "currentDate" => now()->format("jS F Y"),
            ])
                ->format("a4")
                ->base64();
        } catch (\Exception $e) {
            Log::error(
                "Error generating GP letter PDF content for prescription #{$prescription->id}",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            return null;
        }
    }
}
