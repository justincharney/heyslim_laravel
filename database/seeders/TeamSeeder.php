<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a default admin user
        $admin = User::create([
            "name" => "System Admin",
            "email" => "admin@email.com",
            "password" => Hash::make("admin123"),
            "email_verified_at" => now(),
        ]);

        // Create some teams
        $team1 = Team::create([
            "name" => "Main Clinic",
            "description" => "The main clinic team",
        ]);

        $team2 = Team::create([
            "name" => "Second Clinic",
            "description" => "Second clinic team",
        ]);

        // Set the admin's team and role
        $admin->current_team_id = $team1->id;
        $admin->save();
        setPermissionsTeamId($team1->id);
        $admin->assignRole("admin");
    }
}
