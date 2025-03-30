<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\ShopifyService;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Laravel\WorkOS\Http\Requests\AuthKitAccountDeletionRequest;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;

class WorkOSAuthController extends Controller
{
    protected $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    public function showLogin(AuthKitLoginRequest $request)
    {
        return $request->redirect();
    }

    public function authenticate(AuthKitAuthenticationRequest $request)
    {
        $user = $request->authenticate();

        // Check if Shopify customer ID exists for the user
        if (!$user->shopify_customer_id) {
            // Parse name into first and last name components
            $nameParts = explode(" ", $user->name);
            $firstName = array_shift($nameParts);
            $lastName = implode(" ", $nameParts);

            try {
                DB::beginTransaction();

                // Create a Shopify customer
                $shopifyCustomerId = $this->shopifyService->createCustomer(
                    $firstName,
                    $lastName,
                    $user->email
                );

                if (!$shopifyCustomerId) {
                    throw new \Exception("Failed to create Shopify customer");
                } else {
                    // Update user with Shopify customer ID
                    $user->shopify_customer_id = $shopifyCustomerId;
                    // Assign the patient role to the user
                    $user->assignRole("patient");
                    $user->save();
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error(
                    "Shopify customer creation failed during WorkOS authentication",
                    [
                        "email" => $user->email,
                        "workos_id" => $user->workos_id,
                        "error" => $e->getMessage(),
                    ]
                );
                // Delete the user account
                $deletionRequest = app(AuthKitAccountDeletionRequest::class);
                $deletionRequest->delete(fn($user) => $user->delete());
                return redirect(
                    config("app.front_end_url") .
                        "/error?msg=shopify_creation_exception"
                );
            }
        }

        return redirect(config("app.front_end_url") . "/dashboard");
    }

    public function logout(AuthKitLogoutRequest $request)
    {
        return $request->logout();
    }
}
