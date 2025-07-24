<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\UserFile;
use Illuminate\Support\Facades\Log;

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

        // Photo upload requirement - only for patients with questionnaire submissions
        if ($user && $user->needsPhotoUpload()) {
            return response()->json(
                [
                    "message" =>
                        "Please upload your required photo to continue",
                    "error" => "photo_upload_required",
                    "photo_uploaded" => false,
                ],
                403,
            );
        }

        // Skip the profile check if not a patient
        if (!$user || !$user->hasRole("patient")) {
            return $next($request);
        }

        // Check if profile is complete
        if (!$user->isProfileConsideredComplete()) {
            // For API requests, return a structured response with status code
            return response()->json(
                [
                    "message" => "Please complete your profile first",
                    "error" => "incomplete_profile",
                    "profile_completed" => false,
                ],
                403,
            );
        }

        return $next($request);
    }
}
