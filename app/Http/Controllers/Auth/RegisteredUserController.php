<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use App\Services\ShopifyService;

class RegisteredUserController extends Controller
{
    protected $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            "firstName" => ["required", "string", "max:255"],
            "lastName" => ["required", "string", "max:255"],
            "email" => [
                "required",
                "string",
                "lowercase",
                "email",
                "max:255",
                "unique:" . User::class,
            ],
            "password" => ["required", "confirmed", Rules\Password::defaults()],
        ]);

        // Create a Shopify customer
        $shopifyCustomerId = $this->shopifyService->createCustomer(
            $request->firstName,
            $request->lastName,
            $request->email
        );

        if (!$shopifyCustomerId) {
            Log::error(
                "Failed to create Shopify customer during registration",
                [
                    "email" => $request->email,
                ]
            );
            return response()->json(
                [
                    "message" =>
                        "Registration failed. Unable to create account in our system.",
                ],
                500
            );
        }

        try {
            DB::beginTransaction();

            $name = $request->firstName . " " . $request->lastName;

            $user = User::create([
                "name" => $name,
                "email" => $request->email,
                "password" => Hash::make($request->input("password")),
                "shopify_customer_id" => $shopifyCustomerId,
            ]);

            event(new Registered($user));
            Auth::guard("web")->login($user);

            DB::commit();

            return response()->json(
                [
                    "message" => "Registration successful",
                ],
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error(
                "User creation failed after Shopify customer was created",
                [
                    "email" => $request->email,
                    "shopify_id" => $shopifyCustomerId,
                    "error" => $e->getMessage(),
                ]
            );

            return response()->json(
                [
                    "message" => "Registration failed. Please try again later.",
                ],
                500
            );
        }
    }
}
