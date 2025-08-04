<?php

namespace App\Http\Controllers;

use App\Models\ClinicalPlan;
use App\Models\User;
use App\Models\QuestionnaireSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\SupabaseStorageService;

class PatientController extends Controller
{
    protected $supabaseService;

    public function __construct(SupabaseStorageService $supabaseService)
    {
        $this->supabaseService = $supabaseService;
    }

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
            ->orderBy("created_at", "desc")
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
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // Load patient with all relationships in one query
        $patient = User::with([
            "questionnaireSubmissions" => function ($query) {
                $query
                    ->with("questionnaire")
                    ->where("status", "!=", "draft")
                    ->orderBy("created_at", "desc");
            },
            "clinicalPlansAsPatient" => function ($query) use ($teamId) {
                $query
                    ->with(["provider", "patient"])
                    ->whereHas("provider", function ($q) use ($teamId) {
                        $q->where("current_team_id", $teamId);
                    })
                    ->orderBy("created_at", "desc");
            },
            "prescriptionsAsPatient" => function ($query) use ($teamId) {
                $query
                    ->with(["prescriber", "clinicalPlan", "patient"])
                    ->whereHas("prescriber", function ($q) use ($teamId) {
                        $q->where("current_team_id", $teamId);
                    })
                    ->orderBy("created_at", "desc");
            },
            "checkIns" => function ($query) {
                $query->orderBy("created_at", "desc");
            },
            "userFiles" => function ($query) {
                $query->orderBy("created_at", "desc");
            },
            "soapChartsAsPatient" => function ($query) use ($teamId) {
                $query
                    ->with("provider")
                    ->whereHas("provider", function ($q) use ($teamId) {
                        $q->where("current_team_id", $teamId);
                    })
                    ->orderBy("created_at", "desc");
            },
        ])->findOrFail($patientId);

        // Check if the user has the patient role and belongs to the same team
        if (
            !$patient->hasRole("patient") ||
            $patient->current_team_id !== $teamId
        ) {
            return response()->json(
                [
                    "message" => "Patient not found in your team",
                ],
                404,
            );
        }

        // Load user files and generate signed URLs
        $userFilesWithUrls = $patient->userFiles->map(function ($file) {
            $file->url = $this->supabaseService->createSignedUrl(
                $file->supabase_path,
                3600,
            );
            return $file;
        });

        return response()->json([
            "patient" => $patient,
            "questionnaire_submissions" => $patient->questionnaireSubmissions,
            "clinical_plans" => $patient->clinicalPlansAsPatient,
            "prescriptions" => $patient->prescriptionsAsPatient,
            "checkIns" => $patient->checkIns,
            "user_files" => $userFilesWithUrls,
            "soap_charts" => $patient->soapChartsAsPatient,
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
                403,
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
                404,
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
            $submissionId,
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
                403,
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
                403,
            );
        }

        // Find submitted questionnaires that don't have clinical plans
        $pendingSubmissions = QuestionnaireSubmission::where(
            "status",
            "submitted",
        )
            ->whereDoesntHave("clinicalPlan")
            ->with("user")
            ->whereHas("user", function ($query) use ($teamId) {
                $query->where("current_team_id", $teamId);
            })
            ->get();

        // Extract unique patients from these submissions
        $patientIds = $pendingSubmissions->pluck("user_id")->unique();

        // Get the patient details
        $patients = User::whereIn("id", $patientIds)->get();

        return response()->json([
            "patients" => $patients,
        ]);
    }
}
