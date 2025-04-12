<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        "recharge_subscription_id",
        "recharge_customer_id",
        "shopify_product_id",
        "original_shopify_order_id",
        "product_name",
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
}
