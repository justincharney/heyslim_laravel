<?php

namespace App\Http\Controllers;

use App\Config\ShopifyProductMapping;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConsultationController extends Controller
{
    protected $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    public function createCheckout(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(["message" => "Unauthenticated."], 401);
        }

        $consulatationProductId = ShopifyProductMapping::getConsultationProductId();

        try {
            // For consultation, it's a one-time purchase rather than a subscription
            $cartData = $this->shopifyService->createCheckout(
                $consulatationProductId,
                null, // No submission ID for consultations
                1, // quantity
                false, // not a subscription
                null, // no selling plan
                null, // no discount
                null // no prescription ID
            );

            if (!$cartData || !isset($cartData["checkoutUrl"])) {
                Log::error("Failed to create checkout for consultation", [
                    "user_id" => $user->id,
                ]);
            }

            return response()->json([
                "checkout_url" => $cartData["checkoutUrl"],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                ["message" => "Failed to create checkout."],
                500
            );
        }
    }
}
