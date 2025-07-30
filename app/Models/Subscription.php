<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\ConsultationService;
use Illuminate\Support\Facades\Log;
use App\Models\CheckIn;

class Subscription extends Model
{
    protected $fillable = [
        "chargebee_subscription_id",
        "chargebee_customer_id",
        "chargebee_item_price_id",
        "latest_shopify_order_id",
        "status",
        "questionnaire_submission_id",
        "prescription_id",
        "user_id",
        "next_charge_scheduled_at",
    ];

    protected $casts = [
        "next_charge_scheduled_at" => "date",
    ];

    /**
     * Get the user who owns the subscription.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the questionnaire submission associated with this subscription.
     */
    public function questionnaireSubmission()
    {
        return $this->belongsTo(QuestionnaireSubmission::class);
    }

    /**
     * Get the prescription associated with this subscription.
     */
    public function prescription()
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * Get the check-ins for this subscription.
     */
    public function checkIns()
    {
        return $this->hasMany(CheckIn::class);
    }
}
