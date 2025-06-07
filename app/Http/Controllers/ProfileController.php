<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
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

        // Define required fields for a complete profile
        $requiredFields = ["date_of_birth", "address", "gender", "ethnicity"];

        // Check if all required fields are filled
        $isComplete = true;
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (empty($user->{$field})) {
                $isComplete = false;
                $missingFields[] = $field;
            }
        }

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
            "date_of_birth" => "required|date|before:today",
            "address" => "required|string",
            "gender" => "required|string|max:255",
            "ethnicity" => "required|string|max:255",
        ]);

        $user->update($validated);

        // Set profile_completed flag
        $user->profile_completed = true;
        $user->save();

        return response()->json([
            "message" => "Profile updated successfully",
        ]);
    }
}
