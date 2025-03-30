<?php

namespace App\Enums;

enum Permission: string
{
    // Patient permissions
    case VIEW_OWN_PROFILE = "view own profile";
    case EDIT_OWN_PROFILE = "edit own profile";
    case VIEW_OWN_QUESTIONNAIRES = "view own questionnaires";
    case SUBMIT_QUESTIONNAIRES = "submit questionnaires";

    // Provider permissions
    case READ_PATIENTS = "read patients";
    case WRITE_PATIENTS = "write patients";
    case READ_QUESTIONNAIRES = "read questionnaires";
    case WRITE_QUESTIONNAIRES = "write questionnaires";
    case READ_TREATMENT_PLANS = "read treatment plans";
    case WRITE_TREATMENT_PLANS = "write treatment plans";
    case READ_PRESCRIPTIONS = "read prescriptions";
    case WRITE_PRESCRIPTIONS = "write prescriptions";

    // Admin permissions
    case MANAGE_TEAMS = "manage teams";
    case MANAGE_USERS = "manage users";
    case MANAGE_ROLES = "manage roles";
    case MANAGE_PERMISSIONS = "manage permissions";
    case MANAGE_SYSTEM = "manage system";
}
