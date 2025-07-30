<?php

namespace App\Http\Controllers;

use App\Config\ShopifyProductMapping;
use App\Services\ShopifyService;
use App\Services\ChargebeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ConsultationController extends Controller
{
    protected $shopifyService;
    protected $chargebeeService;

    public function __construct(
        ShopifyService $shopifyService,
        ChargebeeService $chargebeeService,
    ) {
        $this->shopifyService = $shopifyService;
        $this->chargebeeService = $chargebeeService;
    }

    public function createCheckout(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(["message" => "Unauthenticated."], 401);
        }

        try {
            // Use Chargebee for consultation checkout
            $consultationPriceId =
                ShopifyProductMapping::$chargebeeConsultationPriceId;

            if (!$consultationPriceId) {
                Log::error("Chargebee consultation price ID not configured");
                return response()->json(
                    ["message" => "Consultation service not available."],
                    500,
                );
            }

            $hostedPage = $this->chargebeeService->createConsultationCheckout(
                $user,
                $consultationPriceId,
            );

            if (!$hostedPage || !isset($hostedPage["url"])) {
                Log::error(
                    "Failed to create Chargebee checkout for consultation",
                    [
                        "user_id" => $user->id,
                    ],
                );
                return response()->json(
                    ["message" => "Failed to create checkout."],
                    500,
                );
            }

            return response()->json([
                "checkout_url" => $hostedPage["url"],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                ["message" => "Failed to create checkout."],
                500,
            );
        }
    }
}
