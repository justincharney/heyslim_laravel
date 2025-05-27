<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionnaireController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupabaseController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\ClinicalPlanController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\ProfileCompletedMiddleware;
use App\Http\Middleware\SetTeamContextMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserFileController;

Route::post("/auth/exchange-token", [AuthController::class, "exchangeToken"]);

// Routes for any user
Route::middleware(["auth:sanctum"])->group(function () {
    Route::get("/questionnaires/template/{template_id}", [
        QuestionnaireController::class,
        "getTemplate",
    ]);

    Route::get("/supabase/token", [SupabaseController::class, "getToken"]);

    // Authentication routes
    Route::get("/user", [AuthController::class, "user"]);
    Route::post("/login", [AuthController::class, "login"]);
    Route::post("/logout", [AuthController::class, "logout"]);
    // Chat routes
    // Get all chats for the authenticated user
    Route::get("/chats", [ChatController::class, "index"]);

    // Get a specific chat with messages
    Route::get("/chats/{id}", [ChatController::class, "show"]);

    // Send a message in a chat
    Route::post("/chats/{id}/messages", [ChatController::class, "sendMessage"]);

    // Read messages
    Route::post("/chats/{id}/read", [ChatController::class, "markAsRead"]);

    // CheckIns
    Route::get("/check-ins", [CheckInController::class, "index"]);
    Route::get("/check-ins/{id}", [CheckInController::class, "show"]);
    Route::put("/check-ins/{id}", [CheckInController::class, "update"]);
});

// Routes for patients
Route::middleware(["auth:sanctum", "role:patient"])->group(function () {
    // Profile routes
    Route::get("/profile/check", [
        ProfileController::class,
        "checkProfileCompletion",
    ]);
    Route::put("/profile", [ProfileController::class, "updatePatientProfile"]);

    // User File Uploads
    Route::post("/user-files", [UserFileController::class, "store"]);
    Route::get("/user-files", [UserFileController::class, "index"]);
    Route::get("/user-files/{id}", [UserFileController::class, "show"]);
    Route::delete("/user-files/{id}", [UserFileController::class, "destroy"]);

    // Questionnaire routes
    Route::middleware([ProfileCompletedMiddleware::class])
        ->prefix("questionnaires")
        ->group(function () {
            // List all available questionnaires
            Route::get("/", [QuestionnaireController::class, "index"]);

            // Get all questionnaires for authenticated user
            Route::get("/user", [
                QuestionnaireController::class,
                "getPatientQuestionnaires",
            ]);

            // Get detailed questionnaire submission
            Route::get("/{submission_id}", [
                QuestionnaireController::class,
                "getQuestionnaireDetails",
            ]);

            // Initialize a draft questionnaire
            Route::post("/draft", [
                QuestionnaireController::class,
                "initializeDraft",
            ]);

            // Save partial answers
            Route::post("/save-partial", [
                QuestionnaireController::class,
                "savePartial",
            ]);

            // Cancel/delete questionnaire submission
            Route::delete("/{submission_id}", [
                QuestionnaireController::class,
                "cancel",
            ]);

            // Submit completed questionnaire
            Route::post("/submit", [QuestionnaireController::class, "store"]);
        });

    // Appointment Routes
    Route::get("/appointments/providers", [
        AppointmentController::class,
        "getAvailableProviders",
    ]);
    Route::get("/appointments/eligibility", [
        AppointmentController::class,
        "checkEligibility",
    ]);

    // Patient-specific prescription routes
    Route::prefix("patient")->group(function () {
        // Get all prescriptions for the patient
        Route::get("/prescriptions", [PrescriptionController::class, "index"]);

        // Get a specific prescription
        Route::get("/prescriptions/{id}", [
            PrescriptionController::class,
            "show",
        ]);

        // Get the chat associated with a prescription
        Route::get("/prescriptions/{id}/chat", [
            PrescriptionController::class,
            "getChat",
        ]);

        // Subscription management
        Route::get("/subscriptions", [SubscriptionController::class, "index"]);
        Route::get("/prescriptions/{id}/subscription", [
            SubscriptionController::class,
            "show",
        ]);
        Route::post("/subscriptions/{id}/cancel", [
            SubscriptionController::class,
            "cancel",
        ]);
    });
});

// Routes for admins
Route::middleware(["auth:sanctum", "role:admin"])
    ->prefix("admin")
    ->group(function () {
        Route::apiResource("teams", TeamController::class);
        Route::get("teams/{team}/members", [TeamController::class, "members"]);
        Route::post("teams/{team}/members", [
            TeamController::class,
            "addMember",
        ]);
        Route::delete("teams/{team}/members", [
            TeamController::class,
            "removeMember",
        ]);
        Route::post("teams/{team}/roles", [
            TeamController::class,
            "assignRole",
        ]);

        // Get all users (for selecting team members)
        // Route::get("/users", function () {
        //     return response()->json([
        //         "users" => User::all(["id", "name", "email"]),
        //     ]);
        // });

        Route::get("/teams/{team}/available-users", [
            TeamController::class,
            "availableUsers",
        ]);

        // Get all available roles other than 'patient'
        Route::get("/roles", [TeamController::class, "getNonPatientRoles"]);
    });

// Routes for providers
Route::middleware(["auth:sanctum", "role:provider"])
    ->prefix("provider")
    ->group(function () {
        // Clinical plans (writing)
        Route::post("/clinical-plans", [
            ClinicalPlanController::class,
            "store",
        ]);
        Route::put("/clinical-plans/{clinicalPlan}", [
            ClinicalPlanController::class,
            "update",
        ]);

        // Patients needing attention
        Route::get("/patients/needing-clinical-plans", [
            PatientController::class,
            "getPatientsNeedingClinicalPlans",
        ]);

        // Reject questionnaire submission
        Route::put("/questionnaire-submissions/{id}/reject", [
            QuestionnaireController::class,
            "reject",
        ]);
    });

// Routes for pharmacists
Route::middleware(["auth:sanctum", "role:pharmacist|admin"])
    ->prefix("pharmacist")
    ->group(function () {
        // Pharmacist-specific clinical plan actions
        Route::put("/clinical-plans/{clinicalPlan}/agree", [
            ClinicalPlanController::class,
            "agreeAsPharmacist",
        ]);

        Route::get("/clinical-plans/needing-approval", [
            ClinicalPlanController::class,
            "getPlansNeedingPharmacistApproval",
        ]);
    });

// Routes shared between providers and pharmacists
Route::middleware(["auth:sanctum", "role:provider|pharmacist"])->group(
    function () {
        // Patient management (view functionality)
        Route::get("/patients", [PatientController::class, "index"]);
        Route::get("/patients/{patient}", [PatientController::class, "show"]);
        Route::get("/patients/{patient}/questionnaires/{submission}", [
            PatientController::class,
            "showQuestionnaire",
        ]);

        // Prescriptions
        Route::get("/prescriptions", [PrescriptionController::class, "index"]);
        Route::post("/prescriptions", [PrescriptionController::class, "store"]);
        Route::get("/prescriptions/needing-signature", [
            PrescriptionController::class,
            "getNeedingSignature",
        ]);
        Route::get("/prescriptions/{prescription}", [
            PrescriptionController::class,
            "show",
        ]);
        Route::get("/patients/{patient}/prescriptions", [
            PrescriptionController::class,
            "getForPatient",
        ]);
        Route::put("/prescriptions/{prescription}", [
            PrescriptionController::class,
            "update",
        ]);

        // Clinical plans (read-only)
        Route::get("/clinical-plans", [ClinicalPlanController::class, "index"]);
        Route::get("/clinical-plans/without-prescriptions", [
            ClinicalPlanController::class,
            "getPlansWithoutPrescriptions",
        ]);
        Route::get("/clinical-plans/{clinicalPlan}", [
            ClinicalPlanController::class,
            "show",
        ]);

        // Chat actions
        // Close a chat (provider only)
        Route::put("/chats/{id}/close", [ChatController::class, "closeChat"]);
        // Reopen a chat (provider only)
        Route::put("/chats/{id}/reopen", [ChatController::class, "reopenChat"]);

        // Template Access
        Route::get("/clinical-plan-templates", [
            TemplateController::class,
            "listClinicalPlanTemplates",
        ]);
        Route::get("/clinical-plan-templates/{id}", [
            ClinicalPlanController::class,
            "getTemplateData",
        ]);
        Route::get("/prescription-templates", [
            TemplateController::class,
            "listPrescriptionTemplates",
        ]);
        Route::get("/prescription-templates/{id}", [
            PrescriptionController::class,
            "getTemplateData",
        ]);

        // Medication product routes for selection during prescription creation
        Route::get("/medication-products", [
            TemplateController::class,
            "listMedicationProducts",
        ]);
        Route::get("/medication-products/{productGid}/variants", [
            TemplateController::class,
            "getMedicationProductVariants",
        ])->where("productGid", ".*");

        // Profile Routes
        Route::get("/provider/profile", [ProfileController::class, "show"]);
        Route::put("/provider/profile", [ProfileController::class, "update"]);
        Route::put("/provider/profile/password", [
            ProfileController::class,
            "updatePassword",
        ]);

        Route::post("/check-ins/{id}/review", [
            CheckInController::class,
            "review",
        ]);
    }
);
