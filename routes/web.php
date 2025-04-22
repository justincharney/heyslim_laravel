<?php

use App\Http\Controllers\RechargeWebhookController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\YousignWebhookController;
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

// Yousign webhook
Route::post("/webhooks/yousign", [
    YousignWebhookController::class,
    "handleWebhook",
]);

require __DIR__ . "/auth.php";
