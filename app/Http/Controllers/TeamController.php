<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Hash;
use App\Traits\OptimisticLocking;

class TeamController extends Controller
{
    use AuthorizesRequests, OptimisticLocking;
    /**
     * Display a listing of the teams.
     */
    public function index()
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        // Get teams with a count of non-patient users
        $teams = Team::withCount([
            "users" => function ($query) {
                $query->whereDoesntHave("roles", function ($q) {
                    $q->where("name", "patient");
                });
            },
        ])->get();

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

        // Set the team context for retrieving roles
        setPermissionsTeamId($team->id);

        // Get team members with their roles in this specific team
        $members = $team
            ->users()
            ->with("roles")
            ->get()
            ->reject(function ($user) {
                return $user->hasRole("patient");
            })
            ->values();

        return response()->json([
            "team" => [
                "id" => $team->id,
                "name" => $team->name,
                "description" => $team->description,
                "members" => $members,
                "updated_at" => $team->updated_at,
            ],
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
            "last_updated_at" => "required|date", // Client sends back the timestamp for optimistic locking
        ]);

        // Check for conflicts
        if (
            $lockCheck = $this->checkOptimisticLock(
                $team,
                $validated["last_updated_at"]
            )
        ) {
            return response()->json(
                ["message" => $lockCheck["message"]],
                $lockCheck["status"]
            );
        }

        $team->update([
            "name" => $validated["name"],
            "description" => $validated["description"],
        ]);

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

    /*
     * Get non-patient members in the team
     */
    public function availableUsers(Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $teamUserIds = $team->users()->pluck("id");

        $availableUsers = User::whereNotIn("id", $teamUserIds)
            ->with("roles")
            ->get(["id", "name", "email"])
            ->reject(function ($user) {
                return $user->roles->count() === 1 && $user->hasRole("patient");
            })
            ->values();

        return response()->json([
            "users" => $availableUsers,
        ]);
    }

    /*
     * Get all roles other than 'patient'
     */
    public function getNonPatientRoles()
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $roles = Role::where("name", "!=", "patient")->pluck("name");

        return response()->json(["roles" => $roles]);
    }

    /**
     * Create a user and add them to the team
     */
    public function addMember(Request $request, Team $team)
    {
        $this->authorize(Permission::MANAGE_TEAMS);
        // Validation that applies to old and new users
        $baseValidation = [
            "email" => "required|email",
            "role" => "required|string|exists:roles,name",
        ];

        $exitingUser = User::where("email", $request->email)->first();

        if ($exitingUser) {
            // Just the base Validation
            $validated = $request->validate($baseValidation);
            $user = $exitingUser;
        } else {
            $fullValidation = array_merge($baseValidation, [
                "firstName" => "required|string|max:255",
                "lastName" => "required|string|max:255",
                "password" => [
                    "required",
                    "confirmed",
                    Rules\Password::defaults(),
                ],
            ]);

            $validated = $request->validate($fullValidation);
            // Create the user
            $user = User::create([
                "name" =>
                    $validated["firstName"] . " " . $validated["lastName"],
                "email" => $validated["email"],
                "password" => Hash::make($validated["password"]),
            ]);
        }

        // Set the user's team
        $user->current_team_id = $team->id;
        $user->save();

        // Set the team context for permissions
        setPermissionsTeamId($team->id);
        // Remove all roles first
        $user->syncRoles([]);
        // Assign the new role
        $user->assignRole($validated["role"]);

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

        $user = User::findOrFail($validated["user_id"]);

        // Reset current_team_id to null if this was their current team
        if ($user->current_team_id === $team->id) {
            $user->current_team_id = null;
            $user->save();
        }

        $team->users()->detach($validated["user_id"]);

        return response()->json([
            "message" => "User removed from team successfully",
        ]);
    }

    /**
     * Assign a role to a team member.
     */
    public function assignRole(Request $request)
    {
        $this->authorize(Permission::MANAGE_TEAMS);

        $validated = $request->validate([
            "user_id" => "required|exists:users,id",
            "role" => "required|string|exists:roles,name",
        ]);

        $user = User::findOrFail($validated["user_id"]);

        // Could check that user is in the team
        $teamId = getPermissionsTeamId();
        if ($user->current_team_id != $teamId) {
            return response()->json(
                [
                    "message" => "User is not a member of this team",
                ],
                400
            );
        }

        // Remove all roles first by using syncRoles with an empty array
        $user->syncRoles([]);

        // Assign the new role
        $user->assignRole($validated["role"]);

        return response()->json([
            "message" => "Role assigned successfully",
        ]);
    }
}
