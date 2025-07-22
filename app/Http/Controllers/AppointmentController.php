<?php

namespace App\Http\Controllers;

use App\Models\QuestionnaireSubmission;
use App\Models\User;
use App\Notifications\ScheduleConsultationNotification;
use App\Services\CalendlyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppointmentController extends Controller
{
    protected $calendlyService;

    public function __construct(CalendlyService $calendlyService)
    {
        $this->calendlyService = $calendlyService;
    }
    /**
     * Get providers available for appointments
     */
    public function getAvailableProviders(Request $request)
    {
        $patient = auth()->user();
        $teamId = $patient->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to book appointments",
                ],
                403,
            );
        }

        setPermissionsTeamId($teamId);

        // Get providers from the same team who have Calendly URLs
        $providers = User::role("provider")
            ->where("current_team_id", $patient->current_team_id)
            ->whereNotNull("calendly_url")
            ->select(["id", "name", "avatar", "calendly_url"])
            ->get();

        return response()->json([
            "providers" => $providers,
        ]);
    }

    /**
     * Check if patient is eligible to book appointments
     */
    public function checkEligibility(Request $request)
    {
        $patient = auth()->user();

        // Check if the patient has completed any questionnaire submissions
        $hasCompletedQuestionnaire = QuestionnaireSubmission::where(
            "user_id",
            $patient->id,
        )
            ->where("status", "submitted")
            ->exists();

        return response()->json([
            "eligible" => $hasCompletedQuestionnaire,
        ]);
    }

    /**
     * Send a Calendly link to a specific patient
     */
    public function sendCalendlyLink(Request $request, $patientId)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to send appointment links",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // Verify the patient exists and is in the same team
        $patient = User::findOrFail($patientId);

        if (!$patient->hasRole("patient")) {
            return response()->json(
                [
                    "message" => "User is not a patient",
                ],
                400,
            );
        }

        if ($patient->current_team_id !== $teamId) {
            return response()->json(
                [
                    "message" => "Patient not found in your team",
                ],
                404,
            );
        }

        try {
            // Generate Calendly link using the service
            $calendlyResult = $this->calendlyService->selectProviderAndGenerateLink(
                $patient,
            );

            if (!$calendlyResult) {
                Log::error("Failed to generate Calendly link", [
                    "patient_id" => $patient->id,
                    "provider_id" => $user->id,
                ]);

                return response()->json(
                    [
                        "message" =>
                            "Failed to generate appointment link. No available providers with Calendly setup found.",
                    ],
                    500,
                );
            }

            // Send notification to patient
            $patient->notify(
                new ScheduleConsultationNotification(
                    null, // No questionnaire submission
                    $calendlyResult["provider"],
                    $calendlyResult["booking_url"],
                ),
            );

            return response()->json([
                "message" =>
                    "Appointment link sent successfully to " . $patient->email,
                "provider" => $calendlyResult["provider"]->name,
            ]);
        } catch (\Exception $e) {
            Log::error("Exception sending Calendly link", [
                "patient_id" => $patient->id,
                "provider_id" => $user->id,
                "error" => $e->getMessage(),
            ]);

            return response()->json(
                [
                    "message" => "Failed to send appointment link",
                ],
                500,
            );
        }
    }
}
