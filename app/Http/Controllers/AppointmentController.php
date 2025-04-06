<?php

namespace App\Http\Controllers;

use App\Models\QuestionnaireSubmission;
use App\Models\User;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
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
                403
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
            $patient->id
        )
            ->where("status", "submitted")
            ->exists();

        return response()->json([
            "eligible" => $hasCompletedQuestionnaire,
        ]);
    }
}
