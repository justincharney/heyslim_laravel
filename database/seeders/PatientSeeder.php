<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PatientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $teamId = 1; // Set all patients to the first team

        // Create 20 patients
        for ($i = 0; $i < 20; $i++) {
            $firstName = $faker->firstName;
            $lastName = $faker->lastName;

            $user = User::create([
                "name" => $firstName . " " . $lastName,
                "email" => $faker->unique()->safeEmail(),
                "email_verified_at" => now(),
                "password" => Hash::make("Password"), // Default password for all seeded patients
                "remember_token" => Str::random(10),
                "current_team_id" => $teamId,
                "shopify_customer_id" => null,
            ]);

            // Set team context for permissions (important for roles with team context)
            setPermissionsTeamId($teamId);

            // Assign patient role in the team context
            $user->assignRole("patient");
        }

        $this->command->info("Created 20 patients in team id {$teamId}");
    }
}
