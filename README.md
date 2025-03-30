# Project Seeding Guide

This project uses Laravel with [Spatie's Laravel Permission package](https://github.com/spatie/laravel-permission) configured to work with teams. The seeders create roles, permissions, teams, and sample questionnaire data. This README explains the seeding process and how the team context is managed.

Database Setup
--------------

1.  **Migrate the Database**

    Run the migrations to create all necessary tables. This includes the standard Laravel tables along with those required by the Spatie package (roles, permissions, team-specific pivot tables, etc.):

    ```bash
    php artisan migrate
    ```

2.  **`current_team_id` on Users**

    A migration adds a `current_team_id` column to the `users` table. This column can be used to track the "active" team for each user, making it easier to determine the team context throughout the application.

Seeders Overview
----------------

### 1\. RolesAndPermissionsSeeder

-   **Purpose:**\
    Creates all permissions and roles needed by the application, including:
    -   Patient, provider, pharmacist, and admin-specific permissions

    -   **Roles:**

        -   `patient`

        -   `provider`

        -   `pharmacist`

        -   `admin`

-   **Usage:**

    If not already seeded, run:

    ```bash
    php artisan db:seed --class=RolesAndPermissionsSeeder
    ```

### 2\. TeamSeeder

-   **Purpose:**\
    Creates sample teams and a default admin user. It demonstrates how to assign roles within a team context using the Spatie package.

    **Role Assignment:**\
    Uses the team context when assigning the "admin" role.\
    For example, within the seeder:

    ```php
    $admin = User::create([
        "name"              => "System Admin",
        "email"             => "admin@email.com",
        "password"          => Hash::make("admin123"),
        "email_verified_at" => now(),
    ]);

    $team1 = Team::create([
        "name"        => "Main Clinic",
        "description" => "The main clinic team",
    ]);

    // Set the team context for permission operations
    setPermissionsTeamId($team1->id);
    // Assign the admin role using the team context
    $admin->assignRole("admin");
    // Set the admin's current_team_id
    $admin->current_team_id = $team1->id;
    $admin->save();
    ```

-   **Usage:**

    Run the TeamSeeder with:

    ```bash
    php artisan db:seed --class=TeamSeeder
    ```

### 3\. GLP1Seeder

-   **Purpose:**\
    Seeds a GLP-1 Weight Loss Medication Questionnaire along with its associated questions, options, and a draft submission for a user.

-   **Usage:**

    Run the GLP1Seeder with:

    ```bash
    php artisan db:seed --class=GLP1Seeder
    ```

Using Teams with Spatie Laravel Permission
------------------------------------------

When using teams with Spatie's package, it's important to set the team context so that the package queries and assignments are scoped correctly.

-   **Setting the Team Context Globally**

    Use the `setPermissionsTeamId()` method from the PermissionRegistrar before performing operations related to roles or permissions. For example:

-   **Assigning a Role with a Team Context**

    Once the team context is set, you can assign roles without manually passing the team id each time:

    ```php
    $admin->assignRole('admin');
    ```

Additional Notes
----------------

-   **Order of Operations:**\
    Always ensure that the roles and permissions are seeded (or created) before running the TeamSeeder.


-   **Configuration Check:**\
    Verify your `config/permission.php` file to ensure that teams are enabled (`'teams' => true`). This setting allows the package to use team-specific roles and permissions.
