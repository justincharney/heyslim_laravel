<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FrontendTokenSeeder extends Seeder
{
    /**
     * Create a frontend user with a Sanctum token.
     */
    public function run(): void
    {
        // Create or retrieve the frontend user
        $user = User::firstOrNew(
            ["email" => "frontend@heyslim.com"],
            [
                "name" => "Frontend Client",
                "password" => Hash::make("eT9Uh7*jP2vRgK!bS5xZqD@3nF8"),
                "email_verified_at" => now(),
            ]
        );

        if (!$user->exists) {
            $user->save();
            $this->command->info("Frontend user created successfully.");
        } else {
            $this->command->info("Using existing frontend user.");
        }

        // Revoke any existing tokens for this user
        $user->tokens()->delete();

        // Create a new token with full abilities
        $token = $user->createToken("frontend-client", ["*"]);

        // Display the plain text token
        $this->command->info("Generated token: " . $token->plainTextToken);
        $this->command->info(
            "Use this token in your frontend Authorization header: Bearer " .
                $token->plainTextToken
        );
    }
}
