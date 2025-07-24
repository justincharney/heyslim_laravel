<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ShopifyService;

class ProfileController extends Controller
{
    protected $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }
    /**
     * Get the user's profile information.
     */
    public function show(Request $request)
    {
        return response()->json([
            "user" => $request->user(),
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            "name" => "sometimes|string|max:255",
            "email" => [
                "sometimes",
                "email",
                "max:255",
                Rule::unique("users")->ignore($user->id),
            ],
            "registration_number" => "sometimes|string|max:255",
            "avatar" => "nullable|string",
            "calendly_event_type" => "sometimes|nullable|url|max:255",
        ]);

        $user->update($validated);

        return response()->json([
            "message" => "Profile updated successfully",
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            "password" => ["required", "confirmed", Rules\Password::defaults()],
        ]);

        $request->user()->update([
            "password" => Hash::make($validated["password"]),
        ]);

        return response()->json([
            "message" => "Password updated successfully",
        ]);
    }

    public function checkProfileCompletion(Request $request)
    {
        $user = auth()->user();

        $missingFields = $user->getMissingProfileFields();
        $isComplete = empty($missingFields);

        // Update profile_completed status in case it's out of sync
        if ($isComplete !== $user->profile_completed) {
            $user->profile_completed = $isComplete;
            $user->save();
        }

        return response()->json([
            "profile_completed" => $isComplete,
            "missing_fields" => $missingFields,
            "user" => $user,
        ]);
    }

    public function updatePatientProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            "name" => "required|string|max:255",
            "phone_number" => "required|string|max:255",
            "date_of_birth" => "required|date|before:today",
            "address" => "required|string",
            "gender" => "required|string|max:255",
            "ethnicity" => "required|string|max:255",
            "affiliate_id" => "nullable|string|max:255",
        ]);

        DB::beginTransaction();

        try {
            $user->update($validated);

            // Create Shopify customer if they don't have one
            if (!$user->shopify_customer_id) {
                $shopifyCustomerId = null;
                $shopifyPassword = Str::random(12);

                // Try to find existing customer by email first
                $shopifyCustomerId = $this->shopifyService->findCustomerByEmail(
                    $user->email,
                );

                // Create new customer if not found
                if (is_null($shopifyCustomerId)) {
                    // Parse name into first and last name components
                    $nameParts = explode(" ", $validated["name"]);
                    $firstName = array_shift($nameParts);
                    $lastName = implode(" ", $nameParts);

                    $shopifyCustomerId = $this->shopifyService->createCustomer(
                        $firstName,
                        $lastName,
                        $user->email,
                        $shopifyPassword,
                    );

                    if (!$shopifyCustomerId) {
                        throw new \Exception(
                            "Failed to create Shopify customer",
                        );
                    }

                    // Add "authorized" tag to user
                    $tagsAdded = $this->shopifyService->addTagsToCustomer(
                        $shopifyCustomerId,
                        ["authorized"],
                    );
                    if (!$tagsAdded) {
                        throw new \Exception(
                            "Failed to add authorized tag to Shopify customer",
                        );
                    }
                }

                // Update user with Shopify customer info
                $user->shopify_customer_id = $shopifyCustomerId;
                $user->shopify_password = encrypt($shopifyPassword);
            }

            // Set profile_completed flag
            $user->profile_completed = true;
            $user->save();

            DB::commit();

            return response()->json([
                "message" => "Profile updated successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error("Profile update failed", [
                "user_id" => $user->id,
                "email" => $user->email,
                "error" => $e->getMessage(),
            ]);

            return response()->json(
                [
                    "message" => "Profile update failed. Please try again.",
                    "error" => $e->getMessage(),
                ],
                500,
            );
        }
    }
}
