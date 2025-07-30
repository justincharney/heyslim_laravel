<?php

use App\Http\Controllers\ChargebeeWebhookController;

use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\YousignWebhookController;
use Illuminate\Support\Facades\Route;

Route::get("/", function () {
    return ["Laravel" => app()->version()];
});

// For the consultation checkout order
Route::post("/webhooks/shopify/orders/paid", [
    ShopifyWebhookController::class,
    "orderPaid",
]);

Route::post("/webhooks/shopify/orders/fulfilled", [
    ShopifyWebhookController::class,
    "orderFulfilled",
]);

// Chargebee webhooks (with basic authentication)
Route::middleware("webhook.auth")->group(function () {
    Route::post("/webhooks/chargebee/subscription/cancelled", [
        ChargebeeWebhookController::class,
        "subscriptionCancelled",
    ]);
    Route::post("/webhooks/chargebee/subscription/changed", [
        ChargebeeWebhookController::class,
        "subscriptionChanged",
    ]);
    Route::post("/webhooks/chargebee/payment/succeeded", [
        ChargebeeWebhookController::class,
        "paymentSucceeded",
    ]);
});

// Yousign webhook
Route::post("/webhooks/yousign", [
    YousignWebhookController::class,
    "handleWebhook",
]);

require __DIR__ . "/auth.php";
