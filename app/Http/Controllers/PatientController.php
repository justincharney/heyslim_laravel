<?php

namespace App\Http\Controllers;

use App\Models\ClinicalPlan;
use App\Models\User;
use App\Models\QuestionnaireSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        // Get the current provider's team
        $teamId = Auth::user()->current_team_id;

        // Get all patients in this team
        $patients = User::whereHas("roles", function ($query) {
            $query->where("name", "patient");
        })
            ->whereHas("teams", function ($query) use ($teamId) {
                $query->where("id", $teamId);
            })
            ->get();

        return response()->json([
            "patients" => $patients,
        ]);
    }

    /**
     * Display the specified patient.
     */
    public function show($patientId)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view patient details",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $patient = User::findOrFail($patientId);

        // Check if the user has the patient role and belongs to the same team
        if (
            !$patient->hasRole("patient") ||
            $patient->current_team_id !== $teamId
        ) {
            return response()->json(
                [
                    "message" => "Patient not found in your team",
                ],
                404
            );
        }

        // Load questionnaire submissions (non-draft)
        $submissions = QuestionnaireSubmission::with(["questionnaire"])
            ->where("user_id", $patient->id)
            ->where("status", "!=", "draft")
            ->orderBy("created_at", "desc")
            ->get();

        // Load clinical management plans with team context
        $clinicalPlans = $patient
            ->clinicalPlansAsPatient()
            ->with(["provider", "pharmacist", "patient"])
            ->whereHas("provider", function ($query) use ($teamId) {
                $query->where("current_team_id", $teamId);
            })
            ->orderBy("created_at", "desc")
            ->get();

        // Load prescriptions with team context
        $prescriptions = $patient
            ->prescriptionsAsPatient()
            ->with(["prescriber", "clinicalPlan", "patient"])
            ->whereHas("prescriber", function ($query) use ($teamId) {
                $query->where("current_team_id", $teamId);
            })
            ->orderBy("created_at", "desc")
            ->get();

        return response()->json([
            "patient" => $patient,
            "questionnaire_submissions" => $submissions,
            "clinical_plans" => $clinicalPlans,
            "prescriptions" => $prescriptions,
        ]);
    }

    /**
     * Display the patient's questionnaire submission
     */
    public function showQuestionnaire($patientId, $submissionId)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view patient questionnaires",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $patient = User::findOrFail($patientId);

        // Check if the user has the patient role and belongs to the same team
        if (
            !$patient->hasRole("patient") ||
            $patient->current_team_id !== $teamId
        ) {
            return response()->json(
                [
                    "message" => "Patient not found in your team",
                ],
                404
            );
        }

        $submission = QuestionnaireSubmission::with([
            "questionnaire",
            "questionnaire.questions",
            "questionnaire.questions.options",
            "answers",
        ])
            ->where("user_id", $patient->id)
            ->findOrFail($submissionId);

        // Check if there's a clinical plan associated with this questionnaire submission
        $clinicalPlan = ClinicalPlan::where(
            "questionnaire_submission_id",
            $submissionId
        )->first();

        return response()->json([
            "patient" => $patient,
            "submission" => $submission,
            "clinical_plan" => $clinicalPlan,
        ]);
    }

    /**
     * Get patients who have questionnaire submissions but no clinical plans
     */
    public function getPatientsNeedingClinicalPlans()
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view patients",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // Check if user is a provider, pharmacist or admin
        if (!$user->hasRole(["provider", "pharmacist", "admin"])) {
            return response()->json(
                [
                    "message" =>
                        "You don't have permission to access this endpoint",
                ],
                403
            );
        }

        // Get patients who have questionnaire submissions but no clinical plans
        $patients = User::role("patient")
            ->where("current_team_id", $teamId)
            ->whereHas("questionnaireSubmissions", function ($query) {
                $query->where("status", "submitted");
            })
            ->whereDoesntHave("clinicalPlansAsPatient")
            ->get();

        return response()->json([
            "patients" => $patients,
        ]);
    }
}
