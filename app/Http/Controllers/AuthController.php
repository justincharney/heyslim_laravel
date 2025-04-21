<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Laravel\WorkOS\WorkOS;
use WorkOS\UserManagement;

class AuthController extends Controller
{
    /**
     * Login user and create token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            "email" => "required|email",
            "password" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json(
                [
                    "message" => "Validation error",
                    "errors" => $validator->errors(),
                ],
                422
            );
        }

        // Find user by email
        $user = User::where("email", $request->email)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(
                [
                    "message" => "The provided credentials are incorrect.",
                ],
                401
            );
        }

        // Create token
        $token = $user->createToken("user-token", expiresAt: now()->addHours(2))
            ->plainTextToken;

        return response()->json([
            "user" => $user,
            "token" => $token,
        ]);
    }

    // /**
    //  * Logout user (revoke token)
    //  *
    //  * @param Request $request
    //  * @return \Illuminate\Http\JsonResponse
    //  */
    // public function logout(Request $request)
    // {
    //     // Revoke all tokens...
    //     $request->user()->tokens()->delete();

    //     return response()->json([
    //         "message" => "Logged out successfully",
    //     ]);
    // }

    public function logout(Request $request)
    {
        $userId = $request->user()->id;

        // Get the workos workos_sid
        $workos_sid = Cache::get("workos_sid_" . $userId);

        // Remove the user's tokens
        $request->user()->tokens()->delete();

        // Prepare response
        $response = [
            "message" => "Logged out successfully",
        ];

        // If we have a WorkOS session ID, add logout URL to response and remove from cache
        if ($workos_sid) {
            $logoutUrl = (new UserManagement())->getLogoutUrl($workos_sid);
            $response["workos_logout_url"] = $logoutUrl;
            Cache::forget("workos_sid_" . $userId);
        }

        return response()->json($response);
    }

    /**
     * Get authenticated user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function user(Request $request)
    {
        $user = $request->user();
        $userData = $user->toArray();
        // Add roles to the userData
        $userData["roles"] = $user->getRoleNames();
        return response()->json($userData);
    }

    public function exchangeToken(Request $request)
    {
        $state = $request->input("state");

        // \Log::info("Token exchange requested", ["state" => $state]);

        // Get token from cache
        $token = Cache::get("token_state:{$state}");

        if (!$token) {
            // \Log::warning("Invalid or expired state parameter", [
            //     "state" => $state,
            // ]);
            return response()->json(
                ["error" => "Invalid or expired state parameter"],
                400
            );
        }

        // \Log::info("Token found for state, proceeding with exchange", [
        //     "state" => $state,
        // ]);

        // Remove the used token immediately for security
        Cache::forget("token_state:{$state}");

        // \Log::info("Token exchange completed successfully", [
        //     "state" => $state,
        // ]);

        // Return the token
        return response()->json([
            "token" => $token,
        ]);
    }
}
