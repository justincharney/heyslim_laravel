<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Firebase\JWT\JWT;

class SupabaseController extends Controller
{
    /**
     * Generate a token for Supabase Realtime
     */
    public function getToken(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(["error" => "Unauthorized"], 401);
        }

        $payload = [
            "iss" => env("SUPABASE_URL"),
            "sub" => (string) $user->id,
            "iat" => time(),
            "exp" => time() + 3600, // 1 hour expiration
            "role" => "authenticated",
        ];

        $token = JWT::encode(
            $payload,
            env("SUPABASE_AUTH_JWT_SECRET"),
            "HS256"
        );

        return response()->json(["token" => $token]);
    }
}
