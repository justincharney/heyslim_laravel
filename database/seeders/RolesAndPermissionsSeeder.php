<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Patient permissions
        Permission::create(["name" => "view own profile"]);
        Permission::create(["name" => "edit own profile"]);
        Permission::create(["name" => "view own questionnaires"]);
        Permission::create(["name" => "submit questionnaires"]);

        // Create permissions for providers
        Permission::create(["name" => "read patients"]);
        Permission::create(["name" => "write patients"]);
        Permission::create(["name" => "read questionnaires"]);
        Permission::create(["name" => "write questionnaires"]); // Can approve/disapprove
        Permission::create(["name" => "read treatment plans"]);
        Permission::create(["name" => "write treatment plans"]);
        Permission::create(["name" => "read prescriptions"]);
        Permission::create(["name" => "write prescriptions"]);

        // Create roles and assign permissions
        $patientRole = Role::create(["name" => "patient"]);
        $patientRole->givePermissionTo([
            "view own profile",
            "edit own profile",
            "view own questionnaires",
            "submit questionnaires",
        ]);

        $providerRole = Role::create(["name" => "provider"]);
        $providerRole->givePermissionTo([
            "view own profile",
            "edit own profile",
            "read patients",
            "write patients",
            "read questionnaires",
            "write questionnaires",
            "read treatment plans",
            "write treatment plans",
            "read prescriptions",
            "write prescriptions",
        ]);

        $pharmacistRole = Role::create(["name" => "pharmacist"]);
        $pharmacistRole->givePermissionTo([
            "view own profile",
            "edit own profile",
            "read patients",
            "write patients",
            "read questionnaires",
            "read treatment plans",
            "read prescriptions",
            "write prescriptions",
        ]);
    }
}
