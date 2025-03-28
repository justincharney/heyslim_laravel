<?php

use App\Http\Controllers\QuestionnaireController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;

Route::middleware(["web", "auth:sanctum"])->group(function () {
    Route::get("/user", function (Request $request) {
        return response()->json([auth("web")->user()]);
    });

    Route::post("/register", [RegisteredUserController::class, "store"])->name(
        "register"
    );

    Route::post("/login", [
        AuthenticatedSessionController::class,
        "store",
    ])->name("login");

    Route::post("/forgot-password", [
        PasswordResetLinkController::class,
        "store",
    ])->name("password.email");

    Route::post("/reset-password", [
        NewPasswordController::class,
        "store",
    ])->name("password.store");

    Route::get("/verify-email/{id}/{hash}", VerifyEmailController::class)->name(
        "verification.verify"
    );

    Route::post("/email/verification-notification", [
        EmailVerificationNotificationController::class,
        "store",
    ])->name("verification.send");

    Route::post("/logout", [
        AuthenticatedSessionController::class,
        "destroy",
    ])->name("logout");

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
