<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\CheckIn;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notification;

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
        "phone_number",
        "password",
        "shopify_customer_id",
        "shopify_password",
        "workos_id",
        "avatar",
        "calendly_event_type",
        "current_team_id",
        "registration_number",
        "address",
        "date_of_birth",
        "profile_completed",
        "gender",
        "ethnicity",
        "affiliate_id",
        "zendesk_lead_created_at",
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ["password", "remember_token", "workos_id"];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ["needs_photo_upload"];

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
            "date_of_birth" => "date",
            "profile_completed" => "boolean",
        ];
    }

    public function teams()
    {
        return $this->belongsToMany(
            Team::class,
            config("permission.table_names.model_has_roles"),
            "model_id", // foreign key on the pivot table for the user
            "team_id", // related key on the pivot table for the team
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
     * Get the SOAP charts where the user is the patient.
     */
    public function soapChartsAsPatient()
    {
        return $this->hasMany(SoapChart::class, "patient_id");
    }

    /**
     * Get the SOAP charts where the user is the provider.
     */
    public function soapChartsAsProvider()
    {
        return $this->hasMany(SoapChart::class, "provider_id");
    }

    /**
     * Get the questionnaire submissions for this user.
     */
    public function questionnaireSubmissions()
    {
        return $this->hasMany(QuestionnaireSubmission::class, "user_id");
    }

    /**
     * Get the checkins for the user.
     */
    public function checkIns()
    {
        return $this->hasMany(CheckIn::class);
    }

    /**
     * Get the files uploaded by the user.
     */
    public function userFiles(): HasMany
    {
        return $this->hasMany(UserFile::class);
    }

    /**
     * Define the fields required for a patient profile to be considered complete.
     * This could also be pulled from a configuration file.
     */
    protected function getRequiredProfileFields(): array
    {
        return [
            "date_of_birth",
            "address",
            "gender",
            "ethnicity",
            "phone_number",
        ];
    }

    /**
     * Get an array of profile fields that are currently missing.
     */
    public function getMissingProfileFields(): array
    {
        $missing = [];
        $requiredFields = $this->getRequiredProfileFields();
        foreach ($requiredFields as $field) {
            if (empty($this->{$field})) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Check if the profile is currently complete based on required fields.
     * This does NOT update the profile_completed flag itself.
     */
    public function isProfileConsideredComplete(): bool
    {
        return empty($this->getMissingProfileFields());
    }

    /**
     * Check if the user needs to upload a photo.
     * Only required for patients with questionnaire submissions.
     */
    public function needsPhotoUpload(): bool
    {
        // Only patients need photo uploads
        if (!$this->hasRole("patient")) {
            return false;
        }

        // Only if they have non-draft or non-pending_payment questionnaire submissions
        if (
            !$this->questionnaireSubmissions()
                ->whereNotIn("status", ["draft", "pending_payment"])
                ->exists()
        ) {
            return false;
        }

        // Check if they've already uploaded a photo
        return !$this->userFiles()->exists();
    }

    /**
     * Get the needs photo upload attribute.
     * This accessor automatically includes the photo upload requirement
     * when the user model is serialized.
     */
    public function getNeedsPhotoUploadAttribute(): bool
    {
        return $this->needsPhotoUpload();
    }

    /**
     * Get the weight logs for the user.
     */
    public function weightLogs(): HasMany
    {
        return $this->hasMany(WeightLog::class)->orderBy("log_date", "desc");
    }

    /**
     * Get the latest weight log for the user.
     */
    public function latestWeightLog()
    {
        return $this->hasOne(WeightLog::class)->latestOfMany("log_date");
    }

    /**
     * Get today's weight log for the user.
     */
    public function todaysWeightLog()
    {
        return $this->hasOne(WeightLog::class)->whereDate("log_date", today());
    }

    /**
     * Check if the user has logged weight today.
     */
    public function hasLoggedWeightToday(): bool
    {
        return $this->todaysWeightLog()->exists();
    }

    /**
     * Route notifications for the Twilio channel.
     */
    public function routeNotificationForTwilio(
        Notification $notification,
    ): string {
        return $this->phone_number;
    }
}
