<?php

use App\Http\Controllers\RechargeWebhookController;
use App\Http\Controllers\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::get("/", function () {
    return ["Laravel" => app()->version()];
});

// Route::post("/webhooks/shopify/orders/paid", [
//     ShopifyWebhookController::class,
//     "orderPaid",
// ]);

Route::post("/webhooks/shopify/orders/fulfilled", [
    ShopifyWebhookController::class,
    "orderFulfilled",
]);

// Recharge webhooks
Route::post("/webhooks/recharge/subscription/cancelled", [
    RechargeWebhookController::class,
    "subscriptionCancelled",
]);
Route::post("/webhooks/recharge/order/created", [
    RechargeWebhookController::class,
    "orderCreated",
]);
Route::post("/webhooks/recharge/subscription/created", [
    RechargeWebhookController::class,
    "subscriptionCreated",
]);

require __DIR__ . "/auth.php";
