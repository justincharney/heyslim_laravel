<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
use App\Notifications\ScheduleConsultationNotification;
use Illuminate\Support\Facades\Log;

class ConsultationService
{
    protected $calendlyService;

    public function __construct(CalendlyService $calendlyService)
    {
        $this->calendlyService = $calendlyService;
    }

    /**
     * Send consultation scheduling notification to the patient
     *
     * @param QuestionnaireSubmission $submission
     * @return bool Whether the notification was sent successfully
     */
    public function sendConsultationLink(
        QuestionnaireSubmission $submission
    ): bool {
        try {
            $patient = $submission->user;

            if (!$patient) {
                Log::error("Patient not found for submission", [
                    "submission_id" => $submission->id,
                ]);
                return false;
            }

            // Generate Calendly link with a randomly selected provider
            $result = $this->calendlyService->selectProviderAndGenerateLink(
                $patient
            );

            if (!$result) {
                Log::error("Failed to generate consultation link", [
                    "submission_id" => $submission->id,
                    "patient_id" => $patient->id,
                ]);
                return false;
            }

            // Send notification to patient
            $patient->notify(
                new ScheduleConsultationNotification(
                    $submission,
                    $result["provider"],
                    $result["booking_url"]
                )
            );

            // Log::info("Consultation scheduling notification sent", [
            //     "submission_id" => $submission->id,
            //     "patient_id" => $patient->id,
            //     "provider_id" => $result["provider"]->id,
            // ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Exception sending consultation link", [
                "error" => $e->getMessage(),
                "submission_id" => $submission->id,
            ]);
            return false;
        }
    }
}
