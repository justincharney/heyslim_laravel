<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileCompletedMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Set team context
        setPermissionsTeamId($user->team_id);

        // Skip if not a patient
        if (!$user || !$user->hasRole("patient")) {
            return $next($request);
        }

        // Check if profile is complete
        if (!$user->profile_completed) {
            // For API requests, return a structured response with status code
            return response()->json(
                [
                    "message" => "Please complete your profile first",
                    "error" => "incomplete_profile",
                    "profile_completed" => false,
                ],
                403
            );
        }

        return $next($request);
    }
}
