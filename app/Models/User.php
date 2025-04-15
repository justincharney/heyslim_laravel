<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        "name",
        "email",
        "password",
        "shopify_customer_id",
        "shopify_password",
        "workos_id",
        "avatar",
        "calendly_event_type",
        "current_team_id",
        "registration_number",
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ["password", "remember_token", "workos_id"];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "email_verified_at" => "datetime",
            "password" => "hashed",
        ];
    }

    public function teams()
    {
        return $this->belongsToMany(
            Team::class,
            config("permission.table_names.model_has_roles"),
            "model_id", // foreign key on the pivot table for the user
            "team_id" // related key on the pivot table for the team
        );
    }

    /*
    Determine which team is "active" for a user
    */
    public function currentTeam()
    {
        return $this->belongsTo(Team::class, "current_team_id");
    }

    /**
     * Get the clinical plans where the user is the patient.
     */
    public function clinicalPlansAsPatient()
    {
        return $this->hasMany(ClinicalPlan::class, "patient_id");
    }

    /**
     * Get the clinical management plans where the user is the provider.
     */
    public function clinicalPlansAsProvider()
    {
        return $this->hasMany(ClinicalPlan::class, "provider_id");
    }

    /**
     * Get the clinical plans where the user is the pharmacist.
     */
    public function clinicalPlansAsPharmacist()
    {
        return $this->hasMany(ClinicalPlan::class, "provider_id");
    }

    /**
     * Get the prescriptions where the user is the patient.
     */
    public function prescriptionsAsPatient()
    {
        return $this->hasMany(Prescription::class, "patient_id");
    }

    /**
     * Get the prescriptions where the user is the prescriber.
     */
    public function prescriptionsAsPrescriber()
    {
        return $this->hasMany(Prescription::class, "prescriber_id");
    }

    /**
     * Get the questionnaire submissions for this user.
     */
    public function questionnaireSubmissions()
    {
        return $this->hasMany(QuestionnaireSubmission::class, "user_id");
    }
}
