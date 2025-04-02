<?php

namespace App\Http\Controllers;

use App\Models\ClinicalPlan;
use App\Models\QuestionnaireSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Enums\Permission;

class ClinicalPlanController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of the clinical management plans.
     */
    public function index()
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view clinical management plans",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // If provider, get plans they created for patients in their team
        if ($user->hasRole("provider")) {
            $plans = $user
                ->clinicalPlansAsProvider()
                ->with(["patient", "pharmacist", "provider"])
                ->whereHas("patient", function ($query) use ($teamId) {
                    $query->where("current_team_id", $teamId);
                })
                ->orderBy("created_at", "desc")
                ->get();
        }
        // If pharmacist, get plans where they're the pharmacist or plans in their team
        elseif ($user->hasRole("pharmacist")) {
            $plans = ClinicalPlan::where(function ($query) use ($user) {
                $query
                    ->where("pharmacist_id", $user->id)
                    ->orWhere("pharmacist_id", null);
            })
                ->whereHas("patient", function ($query) use ($teamId) {
                    $query->where("current_team_id", $teamId);
                })
                ->whereHas("provider", function ($query) use ($teamId) {
                    $query->where("current_team_id", $teamId);
                })
                ->with(["patient", "pharmacist", "provider"])
                ->orderBy("created_at", "desc")
                ->get();
        }
        // For admins, get all plans for the current team
        elseif ($user->hasRole("admin")) {
            $plans = ClinicalPlan::whereHas("patient", function ($query) use (
                $teamId
            ) {
                $query->where("current_team_id", $teamId);
            })
                ->with([
                    "patient",
                    "provider",
                    "pharmacist",
                    "questionnaireSubmission.questionnaire",
                ])
                ->orderBy("created_at", "desc")
                ->get();
        } else {
            return response()->json(
                [
                    "message" =>
                        "You do not have permission to view clinical management plans",
                ],
                403
            );
        }

        return response()->json([
            "clinical_plans" => $plans,
        ]);
    }

    /**
     * Store a newly created clinical management plan.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to create clinical management plans",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::WRITE_TREATMENT_PLANS);

        $validated = $request->validate([
            "patient_id" => "required|exists:users,id",
            "questionnaire_submission_id" =>
                "nullable|exists:questionnaire_submissions,id",
            "condition_treated" => "required|string",
            "medicines_that_may_be_prescribed" => "required|string",
            "dose_schedule" => "required|string",
            "guidelines" => "required|string",
            "monitoring_frequency" => "required|string",
            "process_for_reporting_adrs" => "required|string",
            "patient_allergies" => "required|string",
            "status" => "required|in:draft,active,completed,abandoned",
        ]);

        // Verify that the patient belongs to the provider's team
        $patient = User::findOrFail($validated["patient_id"]);
        if ($patient->current_team_id !== $teamId) {
            return response()->json(
                [
                    "message" =>
                        "You can only create plans for patients in your team",
                ],
                403
            );
        }

        // Add the authenticated user (provider) as the creator
        $validated["provider_id"] = auth()->id();
        $validated["provider_agreed_at"] = now();

        DB::beginTransaction();

        try {
            $plan = ClinicalPlan::create($validated);

            DB::commit();

            return response()->json(
                [
                    "message" =>
                        "Clinical management plan created successfully",
                    "clinical_plan" => $plan,
                ],
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to create clinical management plan",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Display the specified clinical management plan.
     */
    public function show($id)
    {
        $this->authorize(Permission::READ_TREATMENT_PLANS);

        $clinicalManagementPlan = ClinicalPlan::findOrFail($id);

        // Load related data
        $clinicalManagementPlan->load([
            "patient",
            "provider",
            "pharmacist",
            "questionnaireSubmission.questionnaire.questions.options",
            "questionnaireSubmission.answers",
            "prescriptions.prescriber",
        ]);

        return response()->json([
            "clinical_plan" => $clinicalManagementPlan,
        ]);
    }

    /**
     * Update the specified clinical management plan.
     */
    public function update(Request $request, $id)
    {
        $this->authorize(Permission::WRITE_TREATMENT_PLANS);

        $clinicalManagementPlan = ClinicalPlan::findOrFail($id);

        $validated = $request->validate([
            "patient_id" => "required|exists:users,id",
            "questionnaire_submission_id" =>
                "nullable|exists:questionnaire_submissions,id",
            "condition_treated" => "required|string",
            "medicines_that_may_be_prescribed" => "required|string",
            "dose_schedule" => "required|string",
            "guidelines" => "required|string",
            "monitoring_frequency" => "required|string",
            "process_for_reporting_adrs" => "required|string",
            "patient_allergies" => "required|string",
            "status" => "required|in:draft,active,completed,abandoned",
        ]);

        // // Check for conflicts
        // if (
        //     $lockCheck = $this->checkOptimisticLock(
        //         $clinicalManagementPlan,
        //         $validated["last_updated_at"]
        //     )
        // ) {
        //     return response()->json(
        //         ["message" => $lockCheck["message"]],
        //         $lockCheck["status"]
        //     );
        // }

        DB::beginTransaction();

        try {
            // unset($validated["last_updated_at"]);

            $clinicalManagementPlan->update($validated);

            DB::commit();

            return response()->json([
                "message" => "Clinical management plan updated successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to update clinical management plan",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Pharmacist agrees to the clinical management plan
     */
    public function agreeAsPharmacist(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasRole("pharmacist")) {
            return response()->json(
                [
                    "message" => "Only pharmacists can perform this action",
                ],
                403
            );
        }

        $clinicalManagementPlan = ClinicalPlan::findOrFail($id);

        DB::beginTransaction();

        try {
            $clinicalManagementPlan->update([
                "pharmacist_id" => $user->id,
                "pharmacist_agreed_at" => now(),
                "status" => "active",
            ]);

            DB::commit();

            return response()->json([
                "message" => "Pharmacist agreement recorded successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to record pharmacist agreement",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }
}
