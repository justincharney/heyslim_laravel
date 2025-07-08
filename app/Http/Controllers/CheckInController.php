<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\Prescription;
use App\Models\Subscription;
use App\Models\WeightLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SupabaseStorageService;
use Illuminate\Support\Facades\DB;
use App\Models\UserFile;
use Illuminate\Support\Str;

class CheckInController extends Controller
{
    protected $supabaseStorageService;

    public function __construct(SupabaseStorageService $supabaseStorageService)
    {
        $this->supabaseStorageService = $supabaseStorageService;
    }

    /**
     * Display a listing of the check-ins.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Filter parameters
        $status = $request->input("status");
        $prescriptionId = $request->input("prescription_id");

        // Base query
        $query = CheckIn::query();

        // Set team context
        $teamId = $user->current_team_id;
        setPermissionsTeamId($teamId);

        // For patients, show only their check-ins
        if ($user->hasRole("patient")) {
            $query->where("user_id", $user->id);
        }
        // For providers, show check-ins for patients in their team
        elseif ($user->hasRole(["provider", "pharmacist"])) {
            $teamId = $user->current_team_id;
            $query->whereHas("user", function ($q) use ($teamId) {
                $q->where("current_team_id", $teamId);
            });
        }

        // Apply filters
        if ($status) {
            $query->where("status", $status);
        }

        if ($prescriptionId) {
            $query->where("prescription_id", $prescriptionId);
        }

        // Get results with relationships
        $checkIns = $query
            ->orderBy("due_date", "desc")
            ->with("prescription")
            ->get();

        return response()->json([
            "check_ins" => $checkIns,
        ]);
    }

    /**
     * Store a newly created check-in.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            "prescription_id" => "required|exists:prescriptions,id",
            "subscription_id" => "required|exists:subscriptions,id",
            "due_date" => "required|date",
        ]);

        // Check authorization
        $user = auth()->user();
        $prescription = Prescription::findOrFail($validated["prescription_id"]);

        $teamId = $user->current_team_id;
        setPermissionsTeamId($teamId);

        // Only providers, pharmacists, or system admins can create check-ins
        if (!$user->hasAnyRole(["provider", "pharmacist", "admin"])) {
            return response()->json(
                [
                    "message" => "Unauthorized to create check-ins",
                ],
                403
            );
        }

        // Verify that the prescription belongs to a patient in the provider's team
        $patientTeamId = $prescription->patient->current_team_id;
        if ($patientTeamId !== $user->current_team_id) {
            return response()->json(
                [
                    "message" =>
                        "Cannot create check-ins for patients outside your team",
                ],
                403
            );
        }

        // Create the check-in
        $checkIn = CheckIn::create([
            "user_id" => $prescription->patient_id,
            "prescription_id" => $validated["prescription_id"],
            "subscription_id" => $validated["subscription_id"],
            "status" => "pending",
            "due_date" => $validated["due_date"],
        ]);

        return response()->json(
            [
                "message" => "Check-in created successfully",
                "check_in" => $checkIn,
            ],
            201
        );
    }

    /**
     * Display the specified check-in.
     */
    public function show($id)
    {
        $user = auth()->user();
        $checkIn = CheckIn::with([
            "prescription.clinicalPlan",
            "user",
            "userFile",
        ])->findOrFail($id);

        // Set team context
        setPermissionsTeamId($user->current_team_id);

        // Authorization
        if ($user->hasRole("patient") && $checkIn->user_id !== $user->id) {
            return response()->json(
                [
                    "message" => "Unauthorized to view this check-in",
                ],
                403
            );
        }

        if ($user->hasRole(["provider", "pharmacist"])) {
            // Ensure provider can only see check-ins for patients in their team
            $patientTeamId = $checkIn->user->current_team_id;
            if ($patientTeamId !== $user->current_team_id) {
                return response()->json(
                    [
                        "message" => "Unauthorized to view this check-in",
                    ],
                    403
                );
            }
        }

        // Fetch all weight logs for the user
        $weightLogs = WeightLog::where("user_id", $checkIn->user_id)
            ->orderBy("log_date", "asc")
            ->get();

        // Calculate the rate of weight loss over the last 4 weeks
        $rateOfWeightLoss = null;
        if ($weightLogs->count() >= 2) {
            $endDate = Carbon::parse($checkIn->due_date);
            $startDate = $endDate->copy()->subWeeks(4);

            $logsInPeriod = $weightLogs
                ->where("log_date", ">=", $startDate)
                ->where("log_date", "<=", $endDate);

            if ($logsInPeriod->count() >= 2) {
                $firstLog = $logsInPeriod->first();
                $lastLog = $logsInPeriod->last();

                $weightChange =
                    $firstLog->getWeightInKgAttribute() -
                    $lastLog->getWeightInKgAttribute();
                $daysDifference = Carbon::parse(
                    $firstLog->log_date
                )->diffInDays(Carbon::parse($lastLog->log_date));

                if ($daysDifference > 0) {
                    $rateOfWeightLoss = round(
                        ($weightChange / $daysDifference) * 7,
                        2
                    ); // kg per week
                }
            }
        }

        // Generate a signed URL for the file if it exists
        if ($checkIn->userFile) {
            $signedUrl = $checkIn->userFile->url = $this->supabaseStorageService->createSignedUrl(
                $checkIn->userFile->supabase_path,
                3600
            );
        }

        return response()->json([
            "check_in" => $checkIn,
            "weight_logs" => $weightLogs,
            "rate_of_weight_loss_kg_per_week" => $rateOfWeightLoss,
        ]);
    }

    /**
     * Update the specified check-in (for patient completion).
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $checkIn = CheckIn::findOrFail($id);

        // Set team context
        setPermissionsTeamId($user->current_team_id);

        // Authorization check
        if ($user->hasRole("patient") && $checkIn->user_id !== $user->id) {
            return response()->json(
                [
                    "message" => "Unauthorized to update this check-in",
                ],
                403
            );
        }

        // Prevent updating if check-in is already completed or skipped
        if (in_array($checkIn->status, ["completed", "skipped"])) {
            return response()->json(
                [
                    "message" => "Check-in is already completed or skipped",
                ],
                403
            );
        }

        $validated = $request->validate([
            "questions_and_responses" => "required|string",
            "file" =>
                "nullable|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240",
            "description" => "nullable|string|max:255",
        ]);

        // Parse the JSON string
        $questionsAndResponses = json_decode(
            $validated["questions_and_responses"],
            true
        );

        DB::beginTransaction();
        try {
            $userFileId = null;

            // Handle file upload only if a file is provided
            if ($request->hasFile("file")) {
                $file = $request->file("file");
                $originalFileName = $file->getClientOriginalName();
                $sanitizedFileName =
                    Str::slug(pathinfo($originalFileName, PATHINFO_FILENAME)) .
                    "." .
                    $file->getClientOriginalExtension();
                $fileContent = file_get_contents($file->getRealPath());
                $mimeType = $file->getMimeType();
                $size = $file->getSize();

                // Define path in Supabase for file
                $timestamp = now()->format("YmdHis");
                $supabasePath = "user_uploads/{$user->id}/{$timestamp}_{$sanitizedFileName}";

                // Upload to Supabase
                $uploadedPath = $this->supabaseStorageService->uploadFile(
                    $supabasePath,
                    $fileContent,
                    ["contentType" => $mimeType, "upsert" => false]
                );

                if (!$uploadedPath) {
                    throw new \Exception("Failed to upload file to Supabase");
                }

                // Create UserFile record
                $userFile = UserFile::create([
                    "user_id" => $user->id,
                    "file_name" => $originalFileName,
                    "supabase_path" => $uploadedPath,
                    "mime_type" => $mimeType,
                    "size" => $size,
                    "description" => $request->input("description"),
                    "uploaded_at" => now(),
                ]);

                $userFileId = $userFile->id;
            }

            // Log the weight from the check-in
            $weightResponse = null;
            foreach ($questionsAndResponses as $qr) {
                if (
                    $qr["question"] === "What is your current weight? (kg)" &&
                    isset($qr["response"])
                ) {
                    $weightResponse = $qr["response"];
                    break;
                }
            }

            if ($weightResponse && is_numeric($weightResponse)) {
                WeightLog::updateOrCreate(
                    [
                        "user_id" => $user->id,
                        "log_date" => today()->toDateString(),
                    ],
                    ["weight" => (float) $weightResponse, "unit" => "kg"]
                );
            }

            // Update check-in (with or without file reference)
            $updateData = [
                "questions_and_responses" => $questionsAndResponses,
                "status" => "submitted",
                "completed_at" => now(),
            ];

            // Only set user_file_id if a file was uploaded
            if ($userFileId) {
                $updateData["user_file_id"] = $userFileId;
            }

            $checkIn->update($updateData);

            DB::commit();

            return response()->json([
                "message" => "Check-in updated successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating check-in: " . $e->getMessage(), [
                "check_in_id" => $checkIn->id,
                "user_id" => $user->id,
                "trace" => $e->getTraceAsString(),
            ]);

            return response()->json(
                [
                    "message" => "Error updating check-in",
                ],
                500
            );
        }
    }

    /**
     * Review a check-in (for providers).
     */
    public function review(Request $request, $id)
    {
        $user = auth()->user();
        $checkIn = CheckIn::findOrFail($id);

        // Set team context
        setPermissionsTeamId($user->current_team_id);

        // Only providers and pharmacists can review check-ins
        if (!$user->hasAnyRole(["provider", "pharmacist"])) {
            return response()->json(
                [
                    "message" => "Unauthorized to review check-ins",
                ],
                403
            );
        }

        // Ensure provider can only review check-ins for patients in their team
        $patientTeamId = $checkIn->user->current_team_id;
        if ($patientTeamId !== $user->current_team_id) {
            return response()->json(
                [
                    "message" => "Unauthorized to review this check-in",
                ],
                403
            );
        }

        // Add status check: Only allow review if submitted
        $allowedReviewStatuses = ["submitted"];
        if (!in_array($checkIn->status, $allowedReviewStatuses)) {
            return response()->json(
                [
                    "message" =>
                        "This check-in cannot be reviewed. Its current status is '{$checkIn->status}'. Only check-ins with status(es): " .
                        implode(", ", $allowedReviewStatuses) .
                        " can be reviewed.",
                ],
                422
            );
        }

        $validated = $request->validate([
            "provider_notes" => "required|string",
        ]);

        // Update check-in
        $checkIn->update([
            "reviewed_by" => $user->id,
            "status" => "reviewed",
            "reviewed_at" => now(),
            "provider_notes" => $validated["provider_notes"],
        ]);

        return response()->json([
            "message" => "Check-in reviewed successfully",
        ]);
    }

    /**
     * Cancel a check-in.
     */
    public function cancel($id)
    {
        $user = auth()->user();
        $checkIn = CheckIn::findOrFail($id);

        // Set team context
        setPermissionsTeamId($user->current_team_id);

        // Authorization
        if ($user->hasRole("patient") && $checkIn->user_id !== $user->id) {
            return response()->json(
                [
                    "message" => "Unauthorized to cancel this check-in",
                ],
                403
            );
        }

        if ($user->hasRole(["provider", "pharmacist"])) {
            // Ensure provider can only cancel check-ins for patients in their team
            $patientTeamId = $checkIn->user->current_team_id;
            if ($patientTeamId !== $user->current_team_id) {
                return response()->json(
                    [
                        "message" => "Unauthorized to cancel this check-in",
                    ],
                    403
                );
            }
        }

        // Cancel the check-in
        $checkIn->update([
            "status" => "cancelled",
        ]);

        return response()->json([
            "message" => "Check-in cancelled successfully",
        ]);
    }

    /**
     * Generate check-ins for a prescription based on its subscription.
     */
    public function generateForPrescription($prescriptionId)
    {
        $prescription = Prescription::with("subscription")->findOrFail(
            $prescriptionId
        );

        // Check if there's a subscription
        if (!$prescription->subscription) {
            return response()->json(
                [
                    "message" =>
                        "No subscription associated with this prescription",
                ],
                400
            );
        }

        $subscription = $prescription->subscription;

        // Check if there's a next charge date
        if (!$subscription->next_charge_scheduled_at) {
            return response()->json(
                [
                    "message" =>
                        "No next charge date available for the subscription",
                ],
                400
            );
        }

        // Create a check-in due 1 day before the next charge
        $nextChargeDate = Carbon::parse(
            $subscription->next_charge_scheduled_at
        );
        $dueDate = (clone $nextChargeDate)->subDays(1);

        // Check if a check-in already exists for this period
        $existingCheckIn = CheckIn::where("prescription_id", $prescriptionId)
            ->where("subscription_id", $subscription->id)
            ->where("due_date", $dueDate->format("Y-m-d"))
            ->first();

        if ($existingCheckIn) {
            return response()->json([
                "message" => "A check-in already exists for this period",
            ]);
        }

        // Create the check-in
        $checkIn = CheckIn::create([
            "user_id" => $prescription->patient_id,
            "prescription_id" => $prescriptionId,
            "subscription_id" => $subscription->id,
            "status" => "pending",
            "due_date" => $dueDate->format("Y-m-d"),
        ]);

        return response()->json(
            [
                "message" => "Check-in generated successfully",
            ],
            201
        );
    }
}
