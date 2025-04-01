<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Enums\Permission;

class PrescriptionController extends Controller
{
    use AuthorizesRequests;

    /*
    List precriptions associated with or created by user
    */
    public function index()
    {
        $teamId = Auth::user()->current_team_id;
        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view prescriptions",
                ],
                403
            );
        }

        // If provider or pharmacist, get prescriptions they created
        if ($user->hasRole(["provider", "pharmacist"])) {
            $prescriptions = $user
                ->prescriptionsAsPrescriber()
                ->whereHas("patient", function ($query) use ($teamId) {
                    $query->whereHas("teams", function ($subQuery) use (
                        $teamId
                    ) {
                        $subQuery->where("id", $teamId);
                    });
                })
                ->with(["patient:id,name", "clinicalManagementPlan"])
                ->orderBy("created_at", "desc")
                ->get();
        }
        // For admin, get all prescriptions for the team
        elseif ($user->hasRole("admin")) {
            $prescriptions = Prescription::whereHas("patient", function (
                $query
            ) use ($teamId) {
                $query->whereHas("teams", function ($subQuery) use ($teamId) {
                    $subQuery->where("id", $teamId);
                });
            })
                ->with([
                    "patient:id,name",
                    "prescriber:id,name",
                    "clinicalManagementPlan",
                ])
                ->orderBy("created_at", "desc")
                ->get();
        }
        // For other roles (like patients)
        else {
            // Patients can only see their own prescriptions
            $prescriptions = Prescription::where("patient_id", $user->id)
                ->with(["prescriber:id,name", "clinicalManagementPlan"])
                ->orderBy("created_at", "desc")
                ->get();
        }

        return response()->json([
            "prescriptions" => $prescriptions,
        ]);
    }

    /**
     * Store a newly created prescription.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to create prescriptions",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::WRITE_PRESCRIPTIONS);

        $validated = $request->validate([
            "patient_id" => "required|exists:users,id",
            "clinical_plan_id" => "nullable|exists:clinical_plans,id",
            "medication_name" => "required|string|max:255",
            "dose" => "required|string|max:255",
            "schedule" => "required|string|max:255",
            "specific_indications" => "nullable|string",
            "status" => "required|in:active,completed,cancelled",
            "start_date" => "required|date",
            "end_date" => "nullable|date|after_or_equal:start_date",
        ]);

        // Verify that the patient belongs to the prescriber's team
        $patient = User::findOrFail($validated["patient_id"]);
        if ($patient->current_team_id !== $teamId) {
            return response()->json(
                [
                    "message" =>
                        "You can only create prescriptions for patients in your team",
                ],
                403
            );
        }

        // If a clinical management plan was provided, validate that the user can prescribe based on the plan
        if (isset($validated["clinical_management_plan_id"])) {
            $plan = ClinicalManagementPlan::findOrFail(
                $validated["clinical_management_plan_id"]
            );

            // Make sure the plan is active
            if ($plan->status !== "active") {
                return response()->json(
                    [
                        "message" =>
                            "Cannot prescribe from an inactive clinical management plan",
                    ],
                    400
                );
            }

            // Make sure this patient is the one the plan is for
            if ($plan->patient_id != $validated["patient_id"]) {
                return response()->json(
                    [
                        "message" =>
                            "Clinical management plan is for a different patient",
                    ],
                    400
                );
            }

            // Make sure the plan belongs to a provider in the same team
            $provider = User::findOrFail($plan->provider_id);
            if ($provider->current_team_id !== $teamId) {
                return response()->json(
                    [
                        "message" =>
                            "Cannot prescribe from a clinical management plan created by a provider outside your team",
                    ],
                    403
                );
            }

            // If user is a pharmacist, make sure the plan has been agreed to by a pharmacist
            if ($user->hasRole("pharmacist") && !$plan->pharmacist_agreed_at) {
                return response()->json(
                    [
                        "message" =>
                            "This clinical management plan has not been agreed to by a pharmacist",
                    ],
                    400
                );
            }
        }

        // Add the authenticated user as the prescriber
        $validated["prescriber_id"] = auth()->id();

        DB::beginTransaction();

        try {
            $prescription = Prescription::create($validated);

            DB::commit();

            return response()->json(
                [
                    "message" => "Prescription created successfully",
                    "prescription" => $prescription,
                ],
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to create prescription",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Display the specified prescription.
     */
    public function show($id)
    {
        $this->authorize(Permission::READ_PRESCRIPTIONS);

        $prescription = Prescription::findOrFail($id);
        $prescription->load([
            "patient",
            "prescriber",
            "clinicalManagementPlan",
        ]);

        return response()->json([
            "prescription" => $prescription,
        ]);
    }

    /**
     * Update the specified prescription.
     */
    public function update(Request $request, $id)
    {
        $this->authorize(Permission::WRITE_PRESCRIPTIONS);

        $prescription = Prescription::findOrFail($id);

        // Only allow updating certain fields and only by the prescriber
        if ($prescription->prescriber_id !== auth()->id()) {
            return response()->json(
                [
                    "message" =>
                        "Only the original prescriber can update this prescription",
                ],
                403
            );
        }

        $validated = $request->validate([
            "specific_indications" => "nullable|string",
            "status" => "sometimes|in:active,completed,cancelled",
            "end_date" => "nullable|date|after_or_equal:start_date",
        ]);

        $prescription->update($validated);

        return response()->json([
            "message" => "Prescription updated successfully",
            "prescription" => $prescription->fresh(),
        ]);
    }

    /**
     * Get all prescriptions for a patient
     */
    public function getForPatient($patientId)
    {
        $this->authorize(Permission::READ_PRESCRIPTIONS);

        $patient = User::findOrFail($patientId);

        if (!$patient->hasRole("patient")) {
            return response()->json(
                [
                    "message" => "User is not a patient",
                ],
                404
            );
        }

        $prescriptions = $patient
            ->prescriptionsAsPatient()
            ->with(["prescriber", "clinicalPlan"])
            ->orderBy("created_at", "desc")
            ->get();

        return response()->json([
            "patient" => $patient,
            "prescriptions" => $prescriptions,
        ]);
    }
}
