<?php

use App\Http\Controllers\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::get("/", function () {
    return ["Laravel" => app()->version()];
});

Route::post("/webhooks/shopify/orders/paid", [
    ShopifyWebhookController::class,
    "orderPaid",
]);

Route::post("/webhooks/shopify/orders/fulfilled", [
    ShopifyWebhookController::class,
    "orderFulfilled",
]);

require __DIR__ . "/auth.php";
