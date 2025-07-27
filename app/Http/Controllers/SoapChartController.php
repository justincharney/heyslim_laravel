<?php

namespace App\Http\Controllers;

use App\Models\SoapChart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Enums\Permission;

class SoapChartController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of SOAP charts.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view SOAP charts",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // Check if user can read treatment plans (SOAP charts are part of treatment documentation)
        $this->authorize(Permission::READ_TREATMENT_PLANS);

        // Build query with filters
        $query = SoapChart::with(["patient:id,name", "provider:id,name"]);

        // Filter by patient if specified
        if ($request->has("patient_id")) {
            $patientId = $request->input("patient_id");

            // Verify patient is in the same team
            $patient = User::where("id", $patientId)
                ->where("current_team_id", $teamId)
                ->first();

            if (!$patient) {
                return response()->json(
                    [
                        "message" => "Patient not found in your team",
                    ],
                    404,
                );
            }

            $query->forPatient($patientId);
        }

        // Filter by provider if specified
        if ($request->has("provider_id")) {
            $query->byProvider($request->input("provider_id"));
        }

        // Filter by status if specified
        if ($request->has("status")) {
            $query->byStatus($request->input("status"));
        }

        // Filter by encounter type if specified
        if ($request->has("encounter_type")) {
            $query->where("encounter_type", $request->input("encounter_type"));
        }

        // Filter by date range if specified
        if ($request->has("start_date")) {
            $query->where("created_at", ">=", $request->input("start_date"));
        }
        if ($request->has("end_date")) {
            $query->where("created_at", "<=", $request->input("end_date"));
        }

        // // Search in title or notes
        // if ($request->has("search")) {
        //     $search = $request->input("search");
        //     $query->where(function ($q) use ($search) {
        //         $q->where("title", "like", "%{$search}%")
        //             ->orWhere("notes", "like", "%{$search}%")
        //             ->orWhere("subjective", "like", "%{$search}%")
        //             ->orWhere("assessment", "like", "%{$search}%");
        //     });
        // }

        // If provider role, show only their charts unless they're viewing a specific patient
        if ($user->hasRole("provider") && !$request->has("patient_id")) {
            $query->byProvider($user->id);
        }

        $charts = $query->orderBy("created_at", "desc");

        return response()->json([
            "soap_charts" => $charts,
        ]);
    }

    /**
     * Store a newly created SOAP chart.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to create SOAP charts",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        // Only providers can create SOAP charts
        $this->authorize(Permission::WRITE_TREATMENT_PLANS);

        $validated = $request->validate([
            "patient_id" => "required|exists:users,id",
            "title" => "nullable|string|max:255",
            "subjective" => "nullable|string",
            "objective" => "nullable|string",
            "assessment" => "nullable|string",
            "plan" => "nullable|string",
            "status" => "required|in:draft,completed,reviewed",
        ]);

        // Verify that the patient belongs to the provider's team
        $patient = User::findOrFail($validated["patient_id"]);
        if ($patient->current_team_id !== $teamId) {
            return response()->json(
                [
                    "message" =>
                        "You can only create charts for patients in your team",
                ],
                403,
            );
        }

        // Verify patient has patient role
        if (!$patient->hasRole("patient")) {
            return response()->json(
                [
                    "message" => "User is not a patient",
                ],
                400,
            );
        }

        // Add provider and team info
        $validated["provider_id"] = $user->id;

        try {
            DB::beginTransaction();

            $soapChart = SoapChart::create($validated);

            DB::commit();

            return response()->json(
                [
                    "message" => "SOAP chart created successfully",
                ],
                201,
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to create SOAP chart",
                    "error" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    /**
     * Display the specified SOAP chart.
     */
    public function show($id)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view SOAP charts",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::READ_TREATMENT_PLANS);

        $soapChart = SoapChart::with(["patient", "provider"])->findOrFail($id);

        // If provider, ensure they can see this chart (either theirs or for a patient they can access)
        if (
            $user->hasRole("provider") &&
            $soapChart->provider_id !== $user->id
        ) {
            // Additional check - can they access this patient? (Are they a member of the patient's team?)
            $patient = $soapChart->patient;
            if ($patient->current_team_id !== $teamId) {
                return response()->json(
                    [
                        "message" =>
                            "You do not have access to this SOAP chart",
                    ],
                    403,
                );
            }
        }

        // Determine if user can edit this SOAP chart
        $canEdit = false;
        try {
            // Check if user has write permissions and is the original provider
            $this->authorize(Permission::WRITE_TREATMENT_PLANS);
            $canEdit = $soapChart->provider_id === $user->id;
        } catch (\Exception $e) {
            // User doesn't have write permissions
            $canEdit = false;
        }

        return response()->json([
            "soap_chart" => $soapChart,
            "can_edit" => $canEdit,
        ]);
    }

    /**
     * Update the specified SOAP chart.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to update SOAP charts",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::WRITE_TREATMENT_PLANS);

        $soapChart = SoapChart::findOrFail($id);

        // Only the original provider can update
        if ($soapChart->provider_id !== $user->id) {
            return response()->json(
                [
                    "message" =>
                        "Only the original provider or an admin can update this SOAP chart",
                ],
                403,
            );
        }

        $validated = $request->validate([
            "title" => "sometimes|nullable|string|max:255",
            "subjective" => "sometimes|nullable|string",
            "objective" => "sometimes|nullable|string",
            "assessment" => "sometimes|nullable|string",
            "plan" => "sometimes|nullable|string",
            "status" => "sometimes|in:draft,completed,reviewed",
        ]);

        try {
            DB::beginTransaction();

            $soapChart->update($validated);

            DB::commit();

            return response()->json([
                "message" => "SOAP chart updated successfully",
                "soap_chart" => $soapChart->fresh([
                    "patient:id,name",
                    "provider:id,name",
                ]),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to update SOAP chart",
                    "error" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    /**
     * Remove the specified SOAP chart.
     */
    public function destroy($id)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to delete SOAP charts",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::WRITE_TREATMENT_PLANS);

        $soapChart = SoapChart::findOrFail($id);

        // Only the original provider can delete
        if ($soapChart->provider_id !== $user->id) {
            return response()->json(
                [
                    "message" =>
                        "Only the original provider or an admin can delete this SOAP chart",
                ],
                403,
            );
        }

        try {
            $soapChart->delete();

            return response()->json([
                "message" => "SOAP chart deleted successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json(
                [
                    "message" => "Failed to delete SOAP chart",
                    "error" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    /**
     * Get all SOAP charts for a specific patient.
     */
    public function getForPatient($patientId)
    {
        $user = auth()->user();
        $teamId = $user->current_team_id;

        if (!$teamId) {
            return response()->json(
                [
                    "message" =>
                        "You must be associated with a team to view patient SOAP charts",
                ],
                403,
            );
        }

        // Set team context for permissions
        setPermissionsTeamId($teamId);

        $this->authorize(Permission::READ_TREATMENT_PLANS);

        $patient = User::findOrFail($patientId);

        // Verify the patient is in the same team
        if ($patient->current_team_id !== $teamId) {
            return response()->json(
                [
                    "message" => "Patient not found in your team",
                ],
                404,
            );
        }

        if (!$patient->hasRole("patient")) {
            return response()->json(
                [
                    "message" => "User is not a patient",
                ],
                400,
            );
        }

        $charts = SoapChart::with(["provider:id,name"])
            ->forPatient($patientId)
            ->orderBy("created_at", "desc")
            ->get();

        return response()->json([
            "patient" => $patient->only(["id", "name", "email"]),
            "soap_charts" => $charts,
        ]);
    }
}
