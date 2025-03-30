<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    /**
     * Display a listing of the teams.
     */
    public function index()
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $teams = Team::all();

        return response()->json([
            "teams" => $teams,
        ]);
    }

    /**
     * Store a newly created team in storage.
     */
    public function store(Request $request)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $validated = $request->validate([
            "name" => "required|string|max:255",
            "description" => "nullable|string",
        ]);

        $team = Team::create($validated);

        return response()->json(
            [
                "message" => "Team created successfully",
                "team" => $team,
            ],
            201
        );
    }

    /**
     * Display the specified team.
     */
    public function show(Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $team->load("users");

        return response()->json([
            "team" => $team,
        ]);
    }

    /**
     * Update the specified team in storage.
     */
    public function update(Request $request, Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $validated = $request->validate([
            "name" => "required|string|max:255",
            "description" => "nullable|string",
        ]);

        $team->update($validated);

        return response()->json([
            "message" => "Team updated successfully",
            "team" => $team,
        ]);
    }

    /**
     * Remove the specified team from storage.
     */
    public function destroy(Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $team->delete();

        return response()->json([
            "message" => "Team deleted successfully",
        ]);
    }

    /**
     * Get the team members.
     */
    public function members(Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $members = $team->users()->with("roles")->get();

        return response()->json([
            "team" => $team,
            "members" => $members,
        ]);
    }

    /**
     * Add a user to a team.
     */
    public function addMember(Request $request, Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $validated = $request->validate([
            "user_id" => "required|exists:users,id",
        ]);

        $user = User::findOrFail($validated["user_id"]);

        // Check if user is already in the team
        if ($team->users()->where("user_id", $user->id)->exists()) {
            return response()->json(
                [
                    "message" => "User is already a member of this team",
                ],
                422
            );
        }

        $team->users()->attach($user->id);

        return response()->json([
            "message" => "User added to team successfully",
        ]);
    }

    /**
     * Remove a user from a team.
     */
    public function removeMember(Request $request, Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $validated = $request->validate([
            "user_id" => "required|exists:users,id",
        ]);

        $team->users()->detach($validated["user_id"]);

        return response()->json([
            "message" => "User removed from team successfully",
        ]);
    }

    /**
     * Assign a role to a team member.
     */
    public function assignRole(Request $request, Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $validated = $request->validate([
            "user_id" => "required|exists:users,id",
            "role" => "required|string|exists:roles,name",
        ]);

        $user = User::findOrFail($validated["user_id"]);

        // Make sure user is in the team
        if (!$team->users()->where("user_id", $user->id)->exists()) {
            return response()->json(
                [
                    "message" => "User is not a member of this team",
                ],
                422
            );
        }

        // Remove any existing roles first
        DB::transaction(function () use ($user, $validated, $team) {
            $user->roles()->detach();
            $user->assignRole($validated["role"], $team);
        });

        return response()->json([
            "message" => "Role assigned successfully",
        ]);
    }
}
