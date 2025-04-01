<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\QuestionnaireController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\ClinicalPlanController;
use App\Http\Middleware\SetTeamContextMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

// Routes for any user
Route::middleware(["web", "auth:sanctum"])->group(function () {
    Route::get("/user", function (Request $request) {
        $user = auth("web")->user();
        if (!$user) {
            return response()->json(["error" => "Unauthenticated"], 401);
        }
        $userData = $user->toArray();
        // Add roles to the userData
        $userData["roles"] = $user->getRoleNames();
        return response()->json($userData);
    });

    Route::post("/login", [
        AuthenticatedSessionController::class,
        "store",
    ])->name("login");

    Route::post("/logout", [
        AuthenticatedSessionController::class,
        "destroy",
    ])->name("logout");
});

// Routes for patients
Route::middleware(["web", "auth:sanctum", "role:patient"])->group(function () {
    // Questionnaire routes
    Route::prefix("questionnaires")->group(function () {
        // Get all questionnaires for authenticated user
        Route::get("/", [
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

        // Submit completed questionnaire
        Route::post("/submit", [QuestionnaireController::class, "store"]);
    });
});

// Routes for admins
Route::middleware(["web", "auth:sanctum", "role:admin"])
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
Route::middleware(["web", "auth:sanctum", "role:provider"])
    ->prefix("provider")
    ->group(function () {
        // Patient management
        Route::get("/patients", [PatientController::class, "index"]);
        Route::get("/patients/{patient}", [PatientController::class, "show"]);
        Route::get("/patients/{patient}/questionnaires/{submission}", [
            PatientController::class,
            "showQuestionnaire",
        ]);

        // Clinical plans
        Route::get("/clinical-plans", [ClinicalPlanController::class, "index"]);
        Route::post("/clinical-plans", [
            ClinicalPlanController::class,
            "store",
        ]);
        Route::post("/clinical-plans/from-questionnaire", [
            ClinicalPlanController::class,
            "createFromQuestionnaire",
        ]);
        Route::get("/clinical-plans/{clinicalPlan}", [
            ClinicalPlanController::class,
            "show",
        ]);
        Route::put("/clinical-plans/{clinicalPlan}", [
            ClinicalPlanController::class,
            "update",
        ]);

        // Prescriptions
        Route::get("/prescriptions", [PrescriptionController::class, "index"]);
        Route::post("/prescriptions", [PrescriptionController::class, "store"]);
        Route::get("/prescriptions/{prescription}", [
            PrescriptionController::class,
            "show",
        ]);
        Route::put("/prescriptions/{prescription}", [
            PrescriptionController::class,
            "update",
        ]);
        Route::get("/patients/{patient}/prescriptions", [
            PrescriptionController::class,
            "getForPatient",
        ]);
    });
