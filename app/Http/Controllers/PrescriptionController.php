<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Prescription;
use App\Models\PrescriptionTemplate;
use App\Models\User;
use App\Models\ClinicalPlan;
use Illuminate\Http\Request;
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
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view prescriptions",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // If provider or pharmacist, get prescriptions they created
        if ($user->hasRole(["provider", "pharmacist"])) {
            $prescriptions = $user
                ->prescriptionsAsPrescriber()
                ->whereHas("patient", function ($query) use ($teamId) {
                    $query->where("current_team_id", $teamId);
                })
                ->with(["patient:id,name", "clinicalPlan"])
                ->orderBy("created_at", "desc")
                ->get();
        }
        // For admin, get all prescriptions for the team
        elseif ($user->hasRole("admin")) {
            $prescriptions = Prescription::whereHas("patient", function (
                $query
            ) use ($teamId) {
                $query->where("current_team_id", $teamId);
            })
                ->with([
                    "patient:id,name",
                    "prescriber:id,name",
                    "clinicalPlan",
                ])
                ->orderBy("created_at", "desc")
                ->get();
        }
        // For other roles (like patients)
        else {
            // Patients can only see their own prescriptions
            $prescriptions = Prescription::where("patient_id", $user->id)
                ->with(["prescriber:id,name", "clinicalPlan"])
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

        // Base validation rules
        $validationRules = [
            "patient_id" => "required|exists:users,id",
            "medication_name" => "required|string|max:255",
            "dose" => "required|string",
            "schedule" => "required|string",
            "refills" => "required|integer|between:0,12",
            "directions" => "nullable|string",
            "status" => "required|in:active,completed,cancelled",
            "start_date" => "required|date",
            "end_date" => "nullable|date|after_or_equal:start_date",
        ];

        // Pharmacists MUST have a clinical plan
        if ($user->hasRole("pharmacist")) {
            $validationRules["clinical_plan_id"] =
                "required|exists:clinical_plans,id";
        } else {
            $validationRules["clinical_plan_id"] =
                "nullable|exists:clinical_plans,id";
        }

        $validated = $request->validate($validationRules);

        // If no end date, set it to one year after start date
        if (empty($validated["end_date"])) {
            $startDate = new \DateTime($validated["start_date"]);
            $validated["end_date"] = $startDate
                ->modify("+1 year")
                ->format("Y-m-d");
        }

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

        // If a clinical plan was provided, validate that the user can prescribe based on the plan
        if (isset($validated["clinical_plan_id"])) {
            $plan = ClinicalPlan::findOrFail($validated["clinical_plan_id"]);

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

            // Create the chat for the prescription
            $chat = Chat::create([
                "prescription_id" => $prescription->id,
                "patient_id" => $validated["patient_id"],
                "provider_id" => $validated["prescriber_id"],
                "status" => "active",
            ]);

            // Add an initial message from the provider
            Message::create([
                "chat_id" => $chat->id,
                "user_id" => $validated["prescriber_id"],
                "message" => "Hello! I've prescribed {$validated["medication_name"]} for you. Feel free to ask any questions about this medication or its usage.",
                "read" => false,
            ]);

            DB::commit();

            return response()->json(
                [
                    "message" => "Prescription created successfully",
                    "prescription" => $prescription,
                    "chat" => $chat,
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
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view prescriptions",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::READ_PRESCRIPTIONS);

        $prescription = Prescription::findOrFail($id);

        // Verify this prescription belongs to the user's team
        $patientTeamId = $prescription->patient->current_team_id;
        $prescriberTeamId = $prescription->prescriber->current_team_id;

        // If user is patient, they can only see their own prescriptions
        if (
            $user->hasRole("patient") &&
            $prescription->patient_id !== $user->id
        ) {
            return response()->json(
                [
                    "message" => "You can only view your own prescriptions",
                ],
                403
            );
        }

        // If user is provider/pharmacist/admin, they can only see prescriptions in their team
        if (
            !$user->hasRole("patient") &&
            $patientTeamId !== $teamId &&
            $prescriberTeamId !== $teamId
        ) {
            return response()->json(
                [
                    "message" => "Prescription not found in your team",
                ],
                404
            );
        }

        $prescription->load(["patient", "prescriber", "clinicalPlan"]);

        return response()->json([
            "prescription" => $prescription,
        ]);
    }

    /**
     * Update the specified prescription.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to update prescriptions",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::WRITE_PRESCRIPTIONS);

        $prescription = Prescription::findOrFail($id);

        // Check if the prescription belongs to user's team
        $prescriptionTeamId = $prescription->patient->current_team_id;
        if ($prescriptionTeamId !== $teamId) {
            return response()->json(
                [
                    "message" =>
                        "You can only update prescriptions in your team",
                ],
                403
            );
        }

        // Only allow updating by the prescriber, except for admins
        if (
            $prescription->prescriber_id !== $user->id &&
            !$user->hasRole("admin")
        ) {
            return response()->json(
                [
                    "message" =>
                        "Only the original prescriber or an admin can update this prescription",
                ],
                403
            );
        }

        $validated = $request->validate([
            "medication_name" => "sometimes|string|max:255",
            "dose" => "sometimes|string",
            "schedule" => "sometimes|string",
            "refills" => "sometimes|integer|between:0,12",
            "directions" => "nullable|string",
            "status" => "sometimes|in:active,completed,cancelled",
            "start_date" => "sometimes|date",
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
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view patient prescriptions",
                ],
                403
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::READ_PRESCRIPTIONS);

        $patient = User::findOrFail($patientId);

        // Verify the patient is in the same team
        if ($patient->current_team_id !== $teamId) {
            return response()->json(
                [
                    "message" => "Patient not found in your team",
                ],
                404
            );
        }

        if (!$patient->hasRole("patient")) {
            return response()->json(
                [
                    "message" => "User is not a patient",
                ],
                400
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

    public function getTemplateData($id)
    {
        $template = PrescriptionTemplate::findOrFail($id);

        // Check access: either global or in user's team
        $user = auth()->user();
        if (
            !$template->is_global &&
            $template->team_id !== $user->current_team_id
        ) {
            return response()->json(
                [
                    "message" => "Template not found in your team",
                ],
                404
            );
        }

        return response()->json([
            "template" => $template,
        ]);
    }
}
