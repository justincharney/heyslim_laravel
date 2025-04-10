<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\ShopifyService;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Laravel\WorkOS\Http\Requests\AuthKitAccountDeletionRequest;
use Laravel\WorkOS\Http\Requests\AuthKitAuthenticationRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLoginRequest;
use Laravel\WorkOS\Http\Requests\AuthKitLogoutRequest;
use Illuminate\Support\Str;

class WorkOSAuthController extends Controller
{
    protected $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Find the team with the fewest patient users
     * Return the ID of the team with the fewest patients, or null if no teams exist
     */
    private function findTeamWithFewestPatients(): int|null
    {
        // Get all teams
        $teams = \App\Models\Team::all();

        if ($teams->isEmpty()) {
            return null;
        }

        $teamCounts = [];

        foreach ($teams as $team) {
            // Set the team context for permissions
            setPermissionsTeamId($team->id);

            // Count users with patient role in this team context
            $patientCount = User::role("patient")->count();

            $teamCounts[$team->id] = $patientCount;
        }

        // Find the team with the minimum count
        return array_keys($teamCounts, min($teamCounts))[0];
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

                // Handle local user creation first
                // Find the team with the fewest patients first
                $teamId = $this->findTeamWithFewestPatients();

                if (!$teamId) {
                    throw new \Exception(
                        "No available team found for patient assignment"
                    );
                }

                // Set the user's current team and team context for roles
                $user->current_team_id = $teamId;
                setPermissionsTeamId($teamId);
                $user->assignRole("patient");

                // Create the shopify password
                $shopifyPassword = Str::random(12);

                // Only create the Shopify customer if local operations succeeded
                $shopifyCustomerId = $this->shopifyService->createCustomer(
                    $firstName,
                    $lastName,
                    $user->email,
                    $shopifyPassword
                );

                if (!$shopifyCustomerId) {
                    throw new \Exception("Failed to create Shopify customer");
                } else {
                    // Add "authorized" tag to user
                    $tagsAdded = $this->shopifyService->addTagsToCustomer(
                        $shopifyCustomerId,
                        ["authorized"]
                    );
                    if (!$tagsAdded) {
                        throw new \Exception(
                            "Failed to add authorized tag to Shopify customer"
                        );
                    }
                    // Update the user with the Shopify customer ID and password
                    $user->shopify_customer_id = $shopifyCustomerId;
                    $user->shopify_password = encrypt($shopifyPassword);
                    $user->save();
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();

                // Clean up Shopify customer if it was created
                if ($shopifyCustomerId) {
                    $this->shopifyService->deleteCustomer($shopifyCustomerId);
                }

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
                        "/error?msg=account_creation_exception"
                );
            }
        }

        return redirect(config("app.front_end_url") . "/post-login");
    }

    public function logout(AuthKitLogoutRequest $request)
    {
        return $request->logout();
    }
}
