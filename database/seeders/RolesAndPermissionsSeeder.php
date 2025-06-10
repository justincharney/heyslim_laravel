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
        Permission::findOrCreate("view own profile");
        Permission::findOrCreate("edit own profile");
        Permission::findOrCreate("view own questionnaires");
        Permission::findOrCreate("submit questionnaires");

        // Create permissions for providers
        Permission::findOrCreate("read patients");
        Permission::findOrCreate("write patients");
        Permission::findOrCreate("read questionnaires");
        Permission::findOrCreate("write questionnaires"); // Can approve/disapprove
        Permission::findOrCreate("read treatment plans");
        Permission::findOrCreate("write treatment plans");
        Permission::findOrCreate("read prescriptions");
        Permission::findOrCreate("write prescriptions");

        // Admin permissions
        Permission::findOrCreate("manage teams");
        Permission::findOrCreate("manage users");
        Permission::findOrCreate("manage roles");
        Permission::findOrCreate("manage permissions");
        Permission::findOrCreate("manage system");

        // Create roles and assign permissions
        $patientRole = Role::findOrCreate("patient");
        $patientRole->syncPermissions([
            "view own profile",
            "edit own profile",
            "view own questionnaires",
            "submit questionnaires",
            "read prescriptions",
        ]);

        $providerRole = Role::findOrCreate("provider");
        $providerRole->syncPermissions([
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

        $pharmacistRole = Role::findOrCreate("pharmacist");
        $pharmacistRole->syncPermissions([
            "view own profile",
            "edit own profile",
            "read patients",
            "write patients",
            "read questionnaires",
            "read treatment plans",
            "read prescriptions",
            // "write prescriptions",
        ]);

        $adminRole = Role::findOrCreate("admin");
        $adminRole->syncPermissions(Permission::all());
    }
}
