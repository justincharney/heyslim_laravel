<?php

use App\Http\Controllers\QuestionnaireController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Routes for any authenticated user
Route::middleware(["web", "auth:sanctum"])->group(function () {
    Route::get("/user", function (Request $request) {
        $user = auth("web")->user();
        $userData = $user->toArray();
        // Add roles to the userData
        $userData["roles"] = $user->getRoleNames();
        return response()->json($userData);
    });
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
