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
}
