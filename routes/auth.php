<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\WorkOSAuthController;

Route::get("login", [WorkOSAuthController::class, "showLogin"])
    ->middleware(["guest"])
    ->name("login");

Route::get("authenticate", [
    WorkOSAuthController::class,
    "authenticate",
])->middleware(["guest"]);

Route::post("logout", [WorkOSAuthController::class, "logout"])
    ->middleware(["auth"])
    ->name("logout");
