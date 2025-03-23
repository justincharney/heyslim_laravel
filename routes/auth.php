<?php

use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;

Route::get("login", function (AuthKitLoginRequest $request) {
    return $request->redirect();
})
    ->middleware(["guest"])
    ->name("login");

Route::get("authenticate", function (AuthKitAuthenticationRequest $request) {
    $request->authenticate();
    return redirect("http://localhost:5173/dashboard");
})->middleware(["guest"]);

Route::post("logout", function (AuthKitLogoutRequest $request) {
    return $request->logout();
})
    ->middleware(["auth"])
    ->name("logout");
